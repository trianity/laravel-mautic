<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Subscription;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use RuntimeException;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Exceptions\SegmentConflict;
use Trianity\LaravelMautic\Exceptions\SubscriptionConflict;
use Trianity\LaravelMautic\Identity\ContactIdentityHasher;
use Trianity\LaravelMautic\Identity\LocalContactReference;

final readonly class DatabaseSubscriptionStore
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private Clock $clock,
        private ?string $databaseConnection = null,
        private string $intentsTable = 'mautic_subscription_intents',
        private string $segmentsTable = 'mautic_subscription_segments',
        private string $locksTable = 'mautic_subscription_segment_locks',
    ) {}

    public function requireTransaction(): void
    {
        if ($this->database()->transactionLevel() < 1) {
            throw new RuntimeException('Subscription delivery must be recorded inside the host contact transaction.');
        }
    }

    public function record(SubscriptionCommand $command, string $contactOperationId): SubscriptionCapture
    {
        $now = $this->timestamp();
        $inserted = $this->database()->table($this->intentsTable)->insertOrIgnore([
            'operation_id' => $command->operationId(),
            'contact_operation_id' => $contactOperationId,
            'connection' => $command->connection,
            'connection_hash' => ContactIdentityHasher::connection($command->connection),
            'local_scope' => $command->localReference->scope,
            'local_key' => $command->localReference->key,
            'event_key' => $command->eventKey,
            'event_hash' => hash('sha256', $command->connection."\0".$command->localReference->scope."\0".$command->localReference->key."\0".$command->eventKey),
            'semantic_hash' => $command->semanticHash(),
            'contact_revision' => $command->contactRevision,
            'contact_payload' => json_encode($command->contactFields->toPayload(), JSON_THROW_ON_ERROR),
            'segment_key_hash' => self::segmentHash($command->connection, $command->segment->key),
            'segment_payload' => json_encode($command->segment->payload(), JSON_THROW_ON_ERROR),
            'status' => SubscriptionStatus::Pending->value,
            'stage' => SubscriptionStage::Recorded->value,
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $record = $this->get($command->operationId());
        if ($record === null || $record->semanticHash !== $command->semanticHash()) {
            throw new SubscriptionConflict('The subscription event key is already associated with different intent.');
        }

        return new SubscriptionCapture($record, $inserted === 1);
    }

    public function get(string $operationId): ?SubscriptionRecord
    {
        $row = $this->database()->table($this->intentsTable)->where('operation_id', $operationId)->first();
        if ($row === null) {
            return null;
        }
        $contact = json_decode((string) $row->contact_payload, true, flags: JSON_THROW_ON_ERROR);
        $segment = json_decode((string) $row->segment_payload, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($contact) || ! is_array($segment)) {
            throw new RuntimeException('Stored subscription payload is invalid.');
        }

        return new SubscriptionRecord(
            (string) $row->operation_id,
            (string) $row->contact_operation_id,
            (string) $row->connection,
            new LocalContactReference((string) $row->local_scope, (string) $row->local_key),
            (string) $row->event_key,
            (string) $row->semantic_hash,
            (int) $row->contact_revision,
            $contact,
            SegmentTarget::fromPayload($segment),
            SubscriptionStatus::from((string) $row->status),
            SubscriptionStage::from((string) $row->stage),
            (int) $row->attempts,
            $row->remote_contact_id === null ? null : (int) $row->remote_contact_id,
            $row->remote_segment_id === null ? null : (int) $row->remote_segment_id,
            $row->lease_owner === null ? null : (string) $row->lease_owner,
            $row->lease_expires_at === null ? null : (int) $row->lease_expires_at,
            $row->last_error_code === null ? null : (string) $row->last_error_code,
        );
    }

    /** @return list<string> */
    public function recoverable(int $limit): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new RuntimeException('Subscription recovery limit must be between 1 and 1000.');
        }
        $rows = $this->database()->table($this->intentsTable)
            ->where(function ($query): void {
                $query->where('status', SubscriptionStatus::Pending->value)
                    ->orWhere(function ($query): void {
                        $query->where('status', SubscriptionStatus::Processing->value)
                            ->where('lease_expires_at', '<=', $this->clock->now());
                    });
            })
            ->orderBy('id')->limit($limit)->pluck('operation_id');

        return array_values(array_filter($rows->all(), is_string(...)));
    }

    public function begin(string $operationId, string $owner, int $leaseSeconds): ?SubscriptionRecord
    {
        $record = $this->get($operationId);
        if ($record === null || $record->status->terminal()) {
            return null;
        }
        if ($record->status === SubscriptionStatus::Processing) {
            if (($record->leaseExpiresAt ?? PHP_INT_MAX) > $this->clock->now()) {
                return null;
            }
            if (in_array($record->stage, [
                SubscriptionStage::SegmentProvisioningInFlight,
                SubscriptionStage::MembershipInFlight,
            ], true)) {
                $this->database()->table($this->intentsTable)
                    ->where('operation_id', $operationId)
                    ->where('status', SubscriptionStatus::Processing->value)
                    ->where('lease_expires_at', '<=', $this->clock->now())
                    ->update([
                        'status' => SubscriptionStatus::Ambiguous->value,
                        'lease_owner' => null,
                        'lease_expires_at' => null,
                        'last_error_code' => $record->stage === SubscriptionStage::MembershipInFlight
                            ? 'membership_worker_lost' : 'segment_provisioning_worker_lost',
                        'updated_at' => $this->timestamp(),
                    ]);

                return null;
            }
        }
        $updated = $this->database()->table($this->intentsTable)
            ->where('operation_id', $operationId)
            ->where('status', $record->status->value)
            ->update([
                'status' => SubscriptionStatus::Processing->value,
                'attempts' => $record->attempts + 1,
                'lease_owner' => $owner,
                'lease_expires_at' => $this->clock->now() + $leaseSeconds,
                'updated_at' => $this->timestamp(),
            ]);

        return $updated === 1 ? $this->get($operationId) : null;
    }

    public function pending(string $operationId, string $owner, SubscriptionStage $stage, ?int $contactId = null): void
    {
        $this->owned($operationId, $owner, [
            'status' => SubscriptionStatus::Pending->value,
            'stage' => $stage->value,
            'remote_contact_id' => $contactId,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'updated_at' => $this->timestamp(),
        ]);
    }

    public function segmentReady(string $operationId, string $owner, int $contactId, int $segmentId): void
    {
        $this->owned($operationId, $owner, [
            'stage' => SubscriptionStage::SegmentReady->value,
            'remote_contact_id' => $contactId,
            'remote_segment_id' => $segmentId,
            'updated_at' => $this->timestamp(),
        ]);
    }

    public function segmentProvisioningInFlight(string $operationId, string $owner, int $contactId): void
    {
        $this->owned($operationId, $owner, [
            'stage' => SubscriptionStage::SegmentProvisioningInFlight->value,
            'remote_contact_id' => $contactId,
            'updated_at' => $this->timestamp(),
        ]);
    }

    public function membershipInFlight(string $operationId, string $owner): void
    {
        $this->owned($operationId, $owner, [
            'stage' => SubscriptionStage::MembershipInFlight->value,
            'updated_at' => $this->timestamp(),
        ]);
    }

    public function complete(string $operationId, string $owner): void
    {
        $this->owned($operationId, $owner, [
            'status' => SubscriptionStatus::Completed->value,
            'stage' => SubscriptionStage::Completed->value,
            'completed_at' => $this->clock->now(),
            'lease_owner' => null,
            'lease_expires_at' => null,
            'last_error_code' => null,
            'updated_at' => $this->timestamp(),
        ]);
    }

    public function stop(string $operationId, string $owner, SubscriptionStatus $status, string $code, ?int $segmentId = null): void
    {
        if (! in_array($status, [SubscriptionStatus::Failed, SubscriptionStatus::Blocked, SubscriptionStatus::Ambiguous], true)) {
            throw new RuntimeException('Only actionable terminal subscription states may be stored.');
        }
        $this->owned($operationId, $owner, [
            'status' => $status->value,
            'remote_segment_id' => $segmentId,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'last_error_code' => $code,
            'updated_at' => $this->timestamp(),
        ]);
    }

    public function resumeMembershipFailure(string $operationId): void
    {
        $updated = $this->database()->table($this->intentsTable)
            ->where('operation_id', $operationId)
            ->where('status', SubscriptionStatus::Failed->value)
            ->where('stage', SubscriptionStage::MembershipInFlight->value)
            ->where('last_error_code', 'membership_write_failed')
            ->update([
                'status' => SubscriptionStatus::Pending->value,
                'stage' => SubscriptionStage::SegmentReady->value,
                'last_error_code' => null,
                'updated_at' => $this->timestamp(),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('Only an unambiguous membership failure may be resumed automatically.');
        }
    }

    public function resumeAfterSegmentAssociation(string $operationId): void
    {
        $record = $this->get($operationId);
        if ($record === null || $record->status !== SubscriptionStatus::Ambiguous
            || $record->lastErrorCode !== 'segment_create_outcome_ambiguous'
            || $this->associatedSegment($record->connection, $record->segment->key) === null) {
            throw new RuntimeException('An ambiguous segment create requires an explicit verified association before resume.');
        }
        $this->database()->table($this->intentsTable)->where('operation_id', $operationId)->update([
            'status' => SubscriptionStatus::Pending->value,
            'stage' => SubscriptionStage::ContactReady->value,
            'last_error_code' => null,
            'updated_at' => $this->timestamp(),
        ]);
    }

    public function associatedSegment(string $connection, string $key): ?int
    {
        $row = $this->database()->table($this->segmentsTable)
            ->where('segment_key_hash', self::segmentHash($connection, $key))->first();
        if ($row === null) {
            return null;
        }
        if ((string) $row->connection !== $connection || (string) $row->segment_key !== $key) {
            throw new SegmentConflict('Stored segment association identity is invalid.');
        }

        return (int) $row->remote_id;
    }

    public function associateSegment(string $connection, string $key, int $remoteId, string $definitionHash): void
    {
        $this->database()->table($this->segmentsTable)->insertOrIgnore([
            'connection' => $connection,
            'connection_hash' => ContactIdentityHasher::connection($connection),
            'segment_key' => $key,
            'segment_key_hash' => self::segmentHash($connection, $key),
            'remote_id' => $remoteId,
            'remote_identity_hash' => hash('sha256', $connection."\0".$remoteId),
            'definition_hash' => $definitionHash,
            'created_at' => $this->timestamp(),
            'updated_at' => $this->timestamp(),
        ]);
        if ($this->associatedSegment($connection, $key) !== $remoteId) {
            throw new SegmentConflict('The stable segment key already has a conflicting association.');
        }
    }

    public function acquireSegment(string $connection, string $key, string $owner, int $leaseSeconds): bool
    {
        $hash = self::segmentHash($connection, $key);
        if ($this->database()->table($this->locksTable)->insertOrIgnore([
            'segment_key_hash' => $hash,
            'owner' => $owner,
            'expires_at' => $this->clock->now() + $leaseSeconds,
        ]) === 1) {
            return true;
        }

        return $this->database()->table($this->locksTable)->where('segment_key_hash', $hash)
            ->where('expires_at', '<=', $this->clock->now())
            ->update(['owner' => $owner, 'expires_at' => $this->clock->now() + $leaseSeconds]) === 1;
    }

    public function releaseSegment(string $connection, string $key, string $owner): void
    {
        $this->database()->table($this->locksTable)
            ->where('segment_key_hash', self::segmentHash($connection, $key))->where('owner', $owner)->delete();
    }

    /** @param callable(): void $callback */
    public function afterCommit(callable $callback): void
    {
        $database = $this->database();
        if (! $database instanceof Connection) {
            throw new RuntimeException('The subscription connection does not support after-commit callbacks.');
        }
        $database->afterCommit($callback);
    }

    /** @param array<string, mixed> $values */
    private function owned(string $operationId, string $owner, array $values): void
    {
        if ($this->database()->table($this->intentsTable)->where('operation_id', $operationId)
            ->where('status', SubscriptionStatus::Processing->value)->where('lease_owner', $owner)->update($values) !== 1) {
            throw new RuntimeException('The subscription delivery lease was lost.');
        }
    }

    private function database(): ConnectionInterface
    {
        return $this->connections->connection($this->databaseConnection);
    }

    private function timestamp(): string
    {
        return date('Y-m-d H:i:s', $this->clock->now());
    }

    private static function segmentHash(string $connection, string $key): string
    {
        return hash('sha256', $connection."\0".$key);
    }
}
