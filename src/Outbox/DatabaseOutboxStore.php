<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Outbox;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use RuntimeException;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Exceptions\OutboxConflict;
use Trianity\LaravelMautic\Identity\ContactIdentityHasher;
use Trianity\LaravelMautic\Identity\LocalContactReference;

final readonly class DatabaseOutboxStore
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private Clock $clock,
        private ?string $databaseConnection = null,
        private string $commandsTable = 'mautic_outbox_commands',
        private string $locksTable = 'mautic_outbox_locks',
        private string $auditsTable = 'mautic_outbox_audits',
    ) {}

    public function record(ContactSyncCommand $command): OutboxRecord
    {
        $payload = json_encode($command->fields->toPayload(), JSON_THROW_ON_ERROR);
        $now = $this->timestamp();
        try {
            $inserted = $this->database()->table($this->commandsTable)->insertOrIgnore([
                'operation_id' => $command->operationId,
                'connection' => $command->connection,
                'local_scope' => $command->localReference->scope,
                'local_key' => $command->localReference->key,
                'aggregate_hash' => $command->aggregateHash(),
                'revision' => $command->revision,
                'operation' => $command->operation->value,
                'source' => $command->source,
                'payload' => $payload,
                'status' => OutboxStatus::Pending->value,
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (QueryException $exception) {
            throw new OutboxConflict('The outbox command identity or aggregate revision is already in use.', previous: $exception);
        }

        $record = $this->get($command->operationId);
        if ($record === null || ($inserted === 0 && ! $this->sameCommand($record, $command))) {
            throw new OutboxConflict('The outbox command identity or aggregate revision is already in use.');
        }

        return $record;
    }

    public function get(string $operationId): ?OutboxRecord
    {
        $row = $this->database()->table($this->commandsTable)->where('operation_id', $operationId)->first();

        return $row === null ? null : $this->map($row);
    }

    /** @param callable(): void $callback */
    public function afterCommit(callable $callback): void
    {
        $database = $this->database();
        if ($database->transactionLevel() > 0) {
            if (! $database instanceof Connection) {
                throw new RuntimeException('The configured outbox connection does not support after-commit callbacks.');
            }
            $database->afterCommit($callback);

            return;
        }

        $callback();
    }

    public function markDispatched(string $operationId): void
    {
        $this->database()->table($this->commandsTable)->where('operation_id', $operationId)->update([
            'dispatched_at' => $this->clock->now(),
            'updated_at' => $this->timestamp(),
        ]);
    }

    /** @return list<string> */
    public function recoverableOperationIds(int $limit): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new RuntimeException('The outbox recovery limit must be between 1 and 1000.');
        }
        $now = $this->clock->now();
        $rows = $this->database()->table($this->commandsTable)
            ->where(function ($query) use ($now): void {
                $query->where('status', OutboxStatus::Pending->value)
                    ->orWhere(function ($query) use ($now): void {
                        $query->where('status', OutboxStatus::RetryWait->value)
                            ->where('next_attempt_at', '<=', $now);
                    })
                    ->orWhere(function ($query) use ($now): void {
                        $query->where('status', OutboxStatus::Processing->value)
                            ->where('lease_expires_at', '<=', $now);
                    });
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('operation_id');

        return array_values(array_filter($rows->all(), is_string(...)));
    }

    public function acquire(string $aggregateHash, string $owner, int $leaseSeconds): bool
    {
        $now = $this->clock->now();
        $expiresAt = $now + $leaseSeconds;
        if ($this->database()->table($this->locksTable)->insertOrIgnore([
            'aggregate_hash' => $aggregateHash,
            'owner' => $owner,
            'expires_at' => $expiresAt,
        ]) === 1) {
            return true;
        }

        return $this->database()->table($this->locksTable)
            ->where('aggregate_hash', $aggregateHash)
            ->where('expires_at', '<=', $now)
            ->update(['owner' => $owner, 'expires_at' => $expiresAt]) === 1;
    }

    public function release(string $aggregateHash, string $owner): void
    {
        $this->database()->table($this->locksTable)
            ->where('aggregate_hash', $aggregateHash)
            ->where('owner', $owner)
            ->delete();
    }

    public function begin(OutboxRecord $record, string $owner, int $leaseSeconds): ?OutboxRecord
    {
        $current = $this->get($record->operationId);
        if ($current === null || $current->status->terminal()) {
            return null;
        }
        $now = $this->clock->now();
        if ($current->status === OutboxStatus::RetryWait && ($current->nextAttemptAt ?? PHP_INT_MAX) > $now) {
            return null;
        }
        if ($current->status === OutboxStatus::Processing && ($current->leaseExpiresAt ?? PHP_INT_MAX) > $now) {
            return null;
        }
        if ($this->database()->table($this->commandsTable)
            ->where('aggregate_hash', $current->aggregateHash)
            ->where('revision', '>', $current->revision)
            ->exists()) {
            $this->transition($current->operationId, OutboxStatus::Superseded, null, null, 'newer_revision', null, null);

            return null;
        }

        $updated = $this->database()->table($this->commandsTable)
            ->where('operation_id', $current->operationId)
            ->where('status', $current->status->value)
            ->update([
                'status' => OutboxStatus::Processing->value,
                'attempts' => $current->attempts + 1,
                'next_attempt_at' => null,
                'lease_owner' => $owner,
                'lease_expires_at' => $now + $leaseSeconds,
                'updated_at' => $this->timestamp(),
            ]);

        return $updated === 1 ? $this->get($current->operationId) : null;
    }

    public function complete(string $operationId, string $owner, ?int $remoteId): void
    {
        $updated = $this->owned($operationId, $owner)->update([
            'status' => OutboxStatus::Completed->value,
            'remote_id' => $remoteId,
            'completed_at' => $this->clock->now(),
            'next_attempt_at' => null,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'last_failure_kind' => null,
            'last_http_status' => null,
            'last_error_code' => null,
            'updated_at' => $this->timestamp(),
        ]);
        if ($updated !== 1) {
            throw new RuntimeException('The outbox completion lease was lost.');
        }
    }

    public function retry(
        string $operationId,
        string $owner,
        int $nextAttemptAt,
        string $failureKind,
        ?int $httpStatus,
        string $errorCode,
    ): void {
        $this->ownedTransition($operationId, $owner, OutboxStatus::RetryWait, $failureKind, $httpStatus, $errorCode, $nextAttemptAt);
    }

    public function fail(
        string $operationId,
        string $owner,
        string $failureKind,
        ?int $httpStatus,
        string $errorCode,
    ): void {
        $this->ownedTransition($operationId, $owner, OutboxStatus::Failed, $failureKind, $httpStatus, $errorCode, null);
    }

    public function ambiguous(string $operationId, ?string $owner, string $errorCode): void
    {
        if ($owner === null) {
            $this->transition($operationId, OutboxStatus::Ambiguous, 'ambiguous_outcome', null, $errorCode, null, null);

            return;
        }
        $this->ownedTransition($operationId, $owner, OutboxStatus::Ambiguous, 'ambiguous_outcome', null, $errorCode, null);
    }

    public function operatorComplete(string $operationId, int $remoteId, string $actor, string $reasonCode): void
    {
        $this->database()->transaction(function () use ($operationId, $remoteId, $actor, $reasonCode): void {
            $updated = $this->database()->table($this->commandsTable)
                ->where('operation_id', $operationId)
                ->where('status', OutboxStatus::Ambiguous->value)
                ->update([
                    'status' => OutboxStatus::Completed->value,
                    'remote_id' => $remoteId,
                    'completed_at' => $this->clock->now(),
                    'updated_at' => $this->timestamp(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Only an ambiguous outbox command may be reconciled.');
            }
            $this->audit($operationId, 'reconcile_remote', $actor, $reasonCode);
        });
    }

    public function operatorRetry(string $operationId, string $actor, string $reasonCode, bool $allowAmbiguous): void
    {
        $allowed = $allowAmbiguous ? [OutboxStatus::Ambiguous->value] : [OutboxStatus::Failed->value];
        $this->database()->transaction(function () use ($operationId, $actor, $reasonCode, $allowed, $allowAmbiguous): void {
            $updated = $this->database()->table($this->commandsTable)
                ->where('operation_id', $operationId)
                ->whereIn('status', $allowed)
                ->update([
                    'status' => OutboxStatus::Pending->value,
                    'next_attempt_at' => null,
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'updated_at' => $this->timestamp(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('The outbox command is not eligible for operator retry.');
            }
            $this->audit($operationId, $allowAmbiguous ? 'confirm_absent' : 'retry_failed', $actor, $reasonCode);
        });
    }

    private function owned(string $operationId, string $owner): Builder
    {
        return $this->database()->table($this->commandsTable)
            ->where('operation_id', $operationId)
            ->where('status', OutboxStatus::Processing->value)
            ->where('lease_owner', $owner);
    }

    private function ownedTransition(
        string $operationId,
        string $owner,
        OutboxStatus $status,
        string $failureKind,
        ?int $httpStatus,
        string $errorCode,
        ?int $nextAttemptAt,
    ): void {
        $updated = $this->owned($operationId, $owner)->update([
            'status' => $status->value,
            'next_attempt_at' => $nextAttemptAt,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'last_failure_kind' => $failureKind,
            'last_http_status' => $httpStatus,
            'last_error_code' => $errorCode,
            'updated_at' => $this->timestamp(),
        ]);
        if ($updated !== 1) {
            throw new RuntimeException('The outbox processing lease was lost.');
        }
    }

    private function transition(
        string $operationId,
        OutboxStatus $status,
        ?string $failureKind,
        ?int $httpStatus,
        ?string $errorCode,
        ?int $nextAttemptAt,
        ?int $remoteId,
    ): void {
        $this->database()->table($this->commandsTable)->where('operation_id', $operationId)->update([
            'status' => $status->value,
            'next_attempt_at' => $nextAttemptAt,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'last_failure_kind' => $failureKind,
            'last_http_status' => $httpStatus,
            'last_error_code' => $errorCode,
            'remote_id' => $remoteId,
            'updated_at' => $this->timestamp(),
        ]);
    }

    private function audit(string $operationId, string $action, string $actor, string $reasonCode): void
    {
        $this->database()->table($this->auditsTable)->insert([
            'operation_id' => $operationId,
            'action' => $action,
            'actor' => $actor,
            'reason_code' => $reasonCode,
            'created_at' => $this->clock->now(),
        ]);
    }

    private function sameCommand(OutboxRecord $record, ContactSyncCommand $command): bool
    {
        return $record->connection === $command->connection
            && $record->localReference->scope === $command->localReference->scope
            && $record->localReference->key === $command->localReference->key
            && $record->revision === $command->revision
            && $record->operation === $command->operation
            && $record->source === $command->source
            && $record->payload === $command->fields->toPayload();
    }

    private function database(): ConnectionInterface
    {
        return $this->connections->connection($this->databaseConnection);
    }

    private function timestamp(): string
    {
        return date('Y-m-d H:i:s', $this->clock->now());
    }

    private function map(object $row): OutboxRecord
    {
        $payload = is_string($row->payload ?? null) ? json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR) : null;
        if (! is_array($payload) || ! is_string($row->operation_id ?? null)
            || ! is_string($row->connection ?? null) || ! is_string($row->local_scope ?? null)
            || ! is_string($row->local_key ?? null) || ! is_string($row->aggregate_hash ?? null)
            || ! is_int($row->revision ?? null) || ! is_string($row->source ?? null)
            || ! is_int($row->attempts ?? null)) {
            throw new RuntimeException('Stored outbox command data is invalid.');
        }
        $operation = is_string($row->operation ?? null) ? OutboxOperation::tryFrom($row->operation) : null;
        $status = is_string($row->status ?? null) ? OutboxStatus::tryFrom($row->status) : null;
        if ($operation === null || $status === null) {
            throw new RuntimeException('Stored outbox command state is invalid.');
        }

        $reference = new LocalContactReference($row->local_scope, $row->local_key);
        if (! hash_equals(ContactIdentityHasher::local($row->connection, $reference), $row->aggregate_hash)) {
            throw new RuntimeException('Stored outbox aggregate identity is invalid.');
        }

        return new OutboxRecord(
            $row->operation_id,
            $row->connection,
            $reference,
            $row->aggregate_hash,
            $row->revision,
            $operation,
            $row->source,
            $payload,
            $status,
            $row->attempts,
            $this->nullableInt($row->next_attempt_at ?? null),
            is_string($row->lease_owner ?? null) ? $row->lease_owner : null,
            $this->nullableInt($row->lease_expires_at ?? null),
            is_string($row->last_failure_kind ?? null) ? $row->last_failure_kind : null,
            $this->nullableInt($row->last_http_status ?? null),
            is_string($row->last_error_code ?? null) ? $row->last_error_code : null,
            $this->nullableInt($row->remote_id ?? null),
            $this->nullableInt($row->dispatched_at ?? null),
            $this->nullableInt($row->completed_at ?? null),
        );
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }
}
