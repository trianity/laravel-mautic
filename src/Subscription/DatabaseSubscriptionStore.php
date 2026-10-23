<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Subscription;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
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
        private string $auditsTable = 'mautic_subscription_audits',
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
            'operation_id' => $command->operationId(), 'contact_operation_id' => $contactOperationId,
            'connection' => $command->connection, 'connection_hash' => ContactIdentityHasher::connection($command->connection),
            'local_scope' => $command->localReference->scope, 'local_key' => $command->localReference->key,
            'event_key' => $command->eventKey,
            'event_hash' => hash('sha256', $command->connection."\0".$command->localReference->scope."\0".$command->localReference->key."\0".$command->eventKey),
            'semantic_hash' => $command->semanticHash(), 'contact_revision' => $command->contactRevision,
            'contact_payload' => json_encode($command->contactFields->toPayload(), JSON_THROW_ON_ERROR),
            'segment_key_hash' => self::segmentHash($command->connection, $command->segment->key),
            'segment_payload' => json_encode($command->segment->payload(), JSON_THROW_ON_ERROR),
            'status' => SubscriptionStatus::Pending->value, 'stage' => SubscriptionStage::Recorded->value,
            'attempts' => 0, 'lease_generation' => 0, 'created_at' => $now, 'updated_at' => $now,
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
            (string) $row->operation_id, (string) $row->contact_operation_id, (string) $row->connection,
            new LocalContactReference((string) $row->local_scope, (string) $row->local_key),
            (string) $row->event_key, (string) $row->semantic_hash, (int) $row->contact_revision, $contact,
            SegmentTarget::fromPayload($segment), SubscriptionStatus::from((string) $row->status),
            SubscriptionStage::from((string) $row->stage), (int) $row->attempts,
            (int) $row->membership_read_attempts, (int) $row->membership_write_attempts,
            $row->remote_contact_id === null ? null : (int) $row->remote_contact_id,
            $row->remote_segment_id === null ? null : (int) $row->remote_segment_id,
            $row->lease_owner === null ? null : (string) $row->lease_owner, (int) $row->lease_generation,
            $row->lease_expires_at === null ? null : (int) $row->lease_expires_at,
            $row->next_attempt_at === null ? null : (int) $row->next_attempt_at,
            $row->last_failure_kind === null ? null : (string) $row->last_failure_kind,
            $row->last_error_code === null ? null : (string) $row->last_error_code,
        );
    }

    /** @return list<string> */
    public function recoverable(int $limit): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new RuntimeException('Subscription recovery limit must be between 1 and 1000.');
        }
        $now = $this->clock->now();
        $rows = $this->database()->table($this->intentsTable)->where(function ($query) use ($now): void {
            $query->where('status', SubscriptionStatus::Pending->value)->orWhere(function ($query): void {
                $query->where('status', SubscriptionStatus::Processing->value)
                    ->where('lease_expires_at', '<=', $this->clock->now());
            })->orWhere(function ($query) use ($now): void {
                $query->where('status', SubscriptionStatus::RetryWait->value)
                    ->where('next_attempt_at', '<=', $now);
            });
        })->orderBy('id')->limit($limit)->pluck('operation_id');

        return array_values(array_filter($rows->all(), is_string(...)));
    }

    public function begin(string $operationId, string $owner, int $leaseSeconds): ?SubscriptionClaim
    {
        $record = $this->get($operationId);
        if ($record === null || $record->status->terminal() || $leaseSeconds < 1) {
            return null;
        }
        $now = $this->clock->now();
        if ($record->status === SubscriptionStatus::RetryWait
            && ($record->nextAttemptAt ?? PHP_INT_MAX) > $now) {
            return null;
        }
        if ($record->status === SubscriptionStatus::Processing) {
            if (($record->leaseExpiresAt ?? PHP_INT_MAX) > $now) {
                return null;
            }
            if (in_array($record->stage, [SubscriptionStage::SegmentProvisioningInFlight, SubscriptionStage::MembershipInFlight], true)) {
                $this->observed($record)->where('lease_expires_at', '<=', $now)->update([
                    'status' => SubscriptionStatus::Ambiguous->value, 'lease_owner' => null, 'lease_expires_at' => null,
                    'last_error_code' => $record->stage === SubscriptionStage::MembershipInFlight
                        ? 'membership_worker_lost' : 'segment_provisioning_worker_lost',
                    'updated_at' => $this->timestamp(),
                ]);

                return null;
            }
        }
        $generation = $record->leaseGeneration + 1;
        $expiresAt = $now + $leaseSeconds;
        if ($this->observed($record)->update([
            'status' => SubscriptionStatus::Processing->value, 'attempts' => $record->attempts + 1,
            'lease_owner' => $owner, 'lease_generation' => $generation, 'lease_expires_at' => $expiresAt,
            'next_attempt_at' => null,
            'updated_at' => $this->timestamp(),
        ]) !== 1) {
            return null;
        }
        if (! $this->database()->table($this->intentsTable)->where('operation_id', $operationId)
            ->where('status', SubscriptionStatus::Processing->value)->where('lease_owner', $owner)
            ->where('lease_generation', $generation)->where('lease_expires_at', $expiresAt)->exists()) {
            return null;
        }
        $fresh = $this->get($operationId);
        if ($fresh === null || $fresh->status !== SubscriptionStatus::Processing
            || $fresh->leaseOwner !== $owner || $fresh->leaseGeneration !== $generation
            || $fresh->leaseExpiresAt !== $expiresAt) {
            return null;
        }

        return new SubscriptionClaim($fresh, $owner, $generation, $expiresAt);
    }

    public function assertOwned(SubscriptionClaim $claim): void
    {
        if (! $this->owned($claim)->exists()) {
            throw new RuntimeException('The subscription delivery reservation is stale or expired.');
        }
    }

    public function now(): int
    {
        return $this->clock->now();
    }

    public function pending(SubscriptionClaim $claim, SubscriptionStage $stage, ?int $contactId = null): void
    {
        $this->transition($claim, ['status' => SubscriptionStatus::Pending->value, 'stage' => $stage->value,
            'remote_contact_id' => $contactId, 'lease_owner' => null, 'lease_expires_at' => null,
            'next_attempt_at' => null]);
    }

    public function segmentReady(SubscriptionClaim $claim, int $contactId, int $segmentId): void
    {
        $this->transition($claim, ['stage' => SubscriptionStage::SegmentReady->value,
            'remote_contact_id' => $contactId, 'remote_segment_id' => $segmentId]);
    }

    public function segmentProvisioningInFlight(SubscriptionClaim $claim, int $contactId): void
    {
        $this->transition($claim, ['stage' => SubscriptionStage::SegmentProvisioningInFlight->value,
            'remote_contact_id' => $contactId]);
    }

    public function membershipInFlight(SubscriptionClaim $claim): void
    {
        $this->transition($claim, [
            'stage' => SubscriptionStage::MembershipInFlight->value,
            'membership_write_attempts' => $claim->record->membershipWriteAttempts + 1,
        ]);
    }

    public function retryMembershipRead(
        SubscriptionClaim $claim,
        int $nextAttemptAt,
        string $failureKind,
        string $code,
    ): void {
        $this->transition($claim, [
            'status' => SubscriptionStatus::RetryWait->value,
            'stage' => SubscriptionStage::SegmentReady->value,
            'membership_read_attempts' => $claim->record->membershipReadAttempts + 1,
            'next_attempt_at' => $nextAttemptAt,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'last_failure_kind' => $failureKind,
            'last_error_code' => $code,
        ]);
    }

    public function retryMembershipWrite(
        SubscriptionClaim $claim,
        int $nextAttemptAt,
        string $failureKind,
        string $code,
    ): void {
        $this->transition($claim, [
            'status' => SubscriptionStatus::RetryWait->value,
            'stage' => SubscriptionStage::SegmentReady->value,
            'next_attempt_at' => $nextAttemptAt,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'last_failure_kind' => $failureKind,
            'last_error_code' => $code,
        ]);
    }

    public function rejectMembership(
        SubscriptionClaim $claim,
        string $failureKind,
        string $code,
        int $segmentId,
        bool $incrementReadAttempts = false,
    ): void {
        $values = [
            'status' => SubscriptionStatus::Failed->value,
            'stage' => SubscriptionStage::SegmentReady->value,
            'remote_segment_id' => $segmentId,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'next_attempt_at' => null,
            'last_failure_kind' => $failureKind,
            'last_error_code' => $code,
        ];
        if ($incrementReadAttempts) {
            $values['membership_read_attempts'] = $claim->record->membershipReadAttempts + 1;
        }
        $this->transition($claim, $values);
    }

    public function complete(SubscriptionClaim $claim): void
    {
        $this->transition($claim, ['status' => SubscriptionStatus::Completed->value,
            'stage' => SubscriptionStage::Completed->value, 'completed_at' => $this->clock->now(),
            'lease_owner' => null, 'lease_expires_at' => null, 'next_attempt_at' => null,
            'last_failure_kind' => null, 'last_error_code' => null]);
    }

    public function stop(
        SubscriptionClaim $claim,
        SubscriptionStatus $status,
        string $code,
        ?int $segmentId = null,
        ?string $failureKind = null,
    ): void {
        if (! in_array($status, [SubscriptionStatus::Failed, SubscriptionStatus::Blocked, SubscriptionStatus::Ambiguous], true)) {
            throw new RuntimeException('Only actionable terminal subscription states may be stored.');
        }
        $this->transition($claim, ['status' => $status->value, 'remote_segment_id' => $segmentId,
            'lease_owner' => null, 'lease_expires_at' => null, 'next_attempt_at' => null,
            'last_failure_kind' => $failureKind, 'last_error_code' => $code]);
    }

    public function associatedSegment(string $connection, string $key): ?int
    {
        $row = $this->database()->table($this->segmentsTable)->where('segment_key_hash', self::segmentHash($connection, $key))->first();
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
            'connection' => $connection, 'connection_hash' => ContactIdentityHasher::connection($connection),
            'segment_key' => $key, 'segment_key_hash' => self::segmentHash($connection, $key),
            'remote_id' => $remoteId, 'remote_identity_hash' => hash('sha256', $connection."\0".$remoteId),
            'definition_hash' => $definitionHash, 'created_at' => $this->timestamp(), 'updated_at' => $this->timestamp(),
        ]);
        if ($this->associatedSegment($connection, $key) !== $remoteId) {
            throw new SegmentConflict('The stable segment key already has a conflicting association.');
        }
    }

    public function acquireSegment(string $connection, string $key, string $operationId, string $owner, int $leaseSeconds): ?SegmentProvisioningClaim
    {
        $hash = self::segmentHash($connection, $key);
        $now = $this->clock->now();
        $expiresAt = $now + $leaseSeconds;
        if ($this->database()->table($this->locksTable)->insertOrIgnore([
            'segment_key_hash' => $hash, 'connection' => $connection, 'segment_key' => $key,
            'operation_id' => $operationId, 'state' => 'resolving', 'owner' => $owner,
            'generation' => 1, 'expires_at' => $expiresAt,
            'created_at' => $this->timestamp(), 'updated_at' => $this->timestamp(),
        ]) === 1) {
            return new SegmentProvisioningClaim($connection, $key, $operationId, $owner, 1, $expiresAt);
        }
        $row = $this->database()->table($this->locksTable)->where('segment_key_hash', $hash)->first();
        if ($row === null) {
            return null;
        }
        if ((string) $row->connection !== $connection || (string) $row->segment_key !== $key) {
            throw new SegmentConflict('Stored segment provisioning identity is invalid.');
        }
        if ((string) $row->state === 'create_in_flight') {
            if ((int) $row->expires_at <= $now) {
                $this->exactProvisioning(
                    (string) $row->segment_key_hash,
                    (string) $row->operation_id,
                    (string) $row->state,
                    (string) $row->owner,
                    (int) $row->generation,
                    (int) $row->expires_at,
                )->update(['state' => 'ambiguous',
                    'last_error_code' => 'segment_create_worker_lost', 'updated_at' => $this->timestamp()]);
            }

            return null;
        }
        if ((string) $row->state === 'ambiguous' || (int) $row->expires_at > $now) {
            return null;
        }
        $generation = (int) $row->generation + 1;
        if ($this->exactProvisioning(
            (string) $row->segment_key_hash,
            (string) $row->operation_id,
            (string) $row->state,
            (string) $row->owner,
            (int) $row->generation,
            (int) $row->expires_at,
        )->update([
            'operation_id' => $operationId, 'state' => 'resolving', 'owner' => $owner,
            'generation' => $generation, 'expires_at' => $expiresAt, 'last_error_code' => null,
            'updated_at' => $this->timestamp(),
        ]) !== 1) {
            return null;
        }
        $claim = new SegmentProvisioningClaim($connection, $key, $operationId, $owner, $generation, $expiresAt);

        return $this->ownedProvisioning($claim)->exists() ? $claim : null;
    }

    public function segmentCreateInFlight(SegmentProvisioningClaim $claim): void
    {
        if ($this->ownedProvisioning($claim)->where('state', 'resolving')->update([
            'state' => 'create_in_flight', 'updated_at' => $this->timestamp(),
        ]) !== 1) {
            throw new RuntimeException('The segment provisioning reservation is stale or expired.');
        }
    }

    public function recordSegmentCreateEvidence(SegmentProvisioningClaim $claim, int $remoteId): void
    {
        if ($this->ownedProvisioning($claim)->where('state', 'create_in_flight')->update([
            'remote_id' => $remoteId, 'updated_at' => $this->timestamp(),
        ]) !== 1) {
            throw new RuntimeException('The segment create evidence reservation was lost.');
        }
    }

    public function preserveSegmentCreateEvidence(SegmentProvisioningClaim $claim, ?int $remoteId, string $code): bool
    {
        $values = ['state' => 'ambiguous', 'last_error_code' => $code, 'updated_at' => $this->timestamp()];
        if ($remoteId !== null) {
            $values['remote_id'] = $remoteId;
        }

        return $this->database()->table($this->locksTable)
            ->where('segment_key_hash', self::segmentHash($claim->connection, $claim->segmentKey))
            ->where('connection', $claim->connection)->where('segment_key', $claim->segmentKey)
            ->where('operation_id', $claim->operationId)->whereIn('state', ['create_in_flight', 'ambiguous'])
            ->update($values) === 1;
    }

    public function releaseSegment(SegmentProvisioningClaim $claim): void
    {
        $this->ownedProvisioning($claim)->where('state', 'resolving')->delete();
    }

    public function completeSegmentProvisioning(SegmentProvisioningClaim $claim): void
    {
        if ($this->ownedProvisioning($claim)->delete() !== 1) {
            throw new RuntimeException('The segment provisioning completion reservation was lost.');
        }
    }

    public function segmentProvisioningBlock(string $connection, string $key): ?object
    {
        $row = $this->database()->table($this->locksTable)->where('segment_key_hash', self::segmentHash($connection, $key))->first();
        if ($row !== null && ((string) $row->connection !== $connection || (string) $row->segment_key !== $key)) {
            throw new SegmentConflict('Stored segment provisioning identity is invalid.');
        }

        return $row;
    }

    public function operatorResumeContact(string $operationId, int $contactId, string $actor, string $reasonCode): void
    {
        $record = $this->requiredActionable($operationId);
        if (! str_starts_with((string) $record->lastErrorCode, 'contact_')
            && $record->lastErrorCode !== 'active_contact_mapping_missing') {
            throw new RuntimeException('The subscription is not waiting for repaired contact work.');
        }
        $this->operatorTransition($record, 'resume_contact', $actor, $reasonCode, 'contact_prerequisite_verified', [
            'status' => SubscriptionStatus::Pending->value,
            'stage' => SubscriptionStage::ContactReady->value,
            'remote_contact_id' => $contactId,
            'last_error_code' => null,
        ]);
    }

    public function operatorCompleteMembership(string $operationId, string $actor, string $reasonCode): void
    {
        $record = $this->requiredMembershipRecovery($operationId);
        $this->operatorTransition($record, 'confirm_membership', $actor, $reasonCode, 'remote_membership_verified', [
            'status' => SubscriptionStatus::Completed->value,
            'stage' => SubscriptionStage::Completed->value,
            'completed_at' => $this->clock->now(),
            'next_attempt_at' => null,
            'last_failure_kind' => null,
            'last_error_code' => null,
        ]);
    }

    public function operatorReplayMembership(string $operationId, string $actor, string $reasonCode): void
    {
        $record = $this->requiredMembershipRecovery($operationId);
        $this->operatorTransition($record, 'authorize_membership_replay', $actor, $reasonCode, 'prior_request_cannot_complete', [
            'status' => SubscriptionStatus::Pending->value,
            'stage' => SubscriptionStage::SegmentReady->value,
            'next_attempt_at' => null,
            'last_failure_kind' => null,
            'last_error_code' => null,
        ]);
    }

    public function operatorAssociateSegment(
        string $operationId,
        int $remoteId,
        string $definitionHash,
        string $actor,
        string $reasonCode,
    ): void {
        $record = $this->requiredActionable($operationId);
        if (! in_array($record->lastErrorCode, [
            'segment_create_outcome_ambiguous', 'segment_create_requires_recovery',
            'segment_provisioning_worker_lost', 'segment_key_provisioning_ambiguous',
        ], true)) {
            throw new RuntimeException('The subscription is not waiting for segment-create recovery.');
        }
        $this->database()->transaction(function () use ($record, $remoteId, $definitionHash, $actor, $reasonCode): void {
            $this->associateSegment($record->connection, $record->segment->key, $remoteId, $definitionHash);
            $deleted = $this->database()->table($this->locksTable)
                ->where('segment_key_hash', self::segmentHash($record->connection, $record->segment->key))
                ->where('connection', $record->connection)->where('segment_key', $record->segment->key)
                ->whereIn('state', ['ambiguous', 'create_in_flight'])->delete();
            if ($deleted !== 1) {
                throw new RuntimeException('The exact unresolved segment provisioning marker no longer exists.');
            }
            $this->operatorTransition($record, 'associate_segment', $actor, $reasonCode, 'remote_segment_verified', [
                'status' => SubscriptionStatus::Pending->value,
                'stage' => SubscriptionStage::ContactReady->value,
                'remote_segment_id' => $remoteId,
                'last_error_code' => null,
            ], false);
        });
    }

    public function operatorResumeSegmentPrerequisite(
        string $operationId,
        int $remoteId,
        string $actor,
        string $reasonCode,
    ): void {
        $record = $this->requiredActionable($operationId);
        if (! in_array($record->lastErrorCode, [
            'segment_key_provisioning_ambiguous', 'segment_provisioning_worker_lost',
        ], true) || $this->associatedSegment($record->connection, $record->segment->key) !== $remoteId) {
            throw new RuntimeException('The subscription does not have the exact repaired segment prerequisite.');
        }
        $this->operatorTransition($record, 'resume_segment_prerequisite', $actor, $reasonCode, 'segment_association_verified', [
            'status' => SubscriptionStatus::Pending->value,
            'stage' => SubscriptionStage::ContactReady->value,
            'remote_segment_id' => $remoteId,
            'last_error_code' => null,
        ]);
    }

    /** @return list<object> */
    public function audits(string $operationId): array
    {
        return array_values($this->database()->table($this->auditsTable)->where('operation_id', $operationId)
            ->orderBy('id')->get()->all());
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

    private function observed(SubscriptionRecord $record): Builder
    {
        $query = $this->database()->table($this->intentsTable)->where('operation_id', $record->operationId)
            ->where('status', $record->status->value)->where('stage', $record->stage->value)
            ->where('attempts', $record->attempts)->where('lease_generation', $record->leaseGeneration);
        $record->leaseOwner === null ? $query->whereNull('lease_owner') : $query->where('lease_owner', $record->leaseOwner);
        $record->leaseExpiresAt === null ? $query->whereNull('lease_expires_at') : $query->where('lease_expires_at', $record->leaseExpiresAt);

        return $query;
    }

    private function requiredActionable(string $operationId): SubscriptionRecord
    {
        $record = $this->get($operationId);
        if ($record === null || ! in_array($record->status, [
            SubscriptionStatus::Blocked, SubscriptionStatus::Failed, SubscriptionStatus::Ambiguous,
        ], true)) {
            throw new RuntimeException('The subscription is not in an actionable recovery state.');
        }

        return $record;
    }

    private function requiredMembershipRecovery(string $operationId): SubscriptionRecord
    {
        $record = $this->requiredActionable($operationId);
        $definite = $record->status === SubscriptionStatus::Failed
            && $record->stage === SubscriptionStage::SegmentReady
            && (str_starts_with((string) $record->lastErrorCode, 'membership_rejected_')
                || str_starts_with((string) $record->lastErrorCode, 'membership_preflight_'));
        $uncertain = $record->stage === SubscriptionStage::MembershipInFlight
            && ($record->lastErrorCode === 'membership_worker_lost'
                || $record->lastErrorCode === 'membership_outcome_ambiguous'
                || $record->lastErrorCode === 'membership_not_verified'
                || str_starts_with((string) $record->lastErrorCode, 'membership_mutation_')
                || str_starts_with((string) $record->lastErrorCode, 'membership_verification_'));
        if ((! $definite && ! $uncertain) || $record->remoteContactId === null || $record->remoteSegmentId === null) {
            throw new RuntimeException('The subscription is not eligible for membership recovery.');
        }

        return $record;
    }

    /** @param array<string, mixed> $values */
    private function operatorTransition(
        SubscriptionRecord $record,
        string $action,
        string $actor,
        string $reasonCode,
        string $outcome,
        array $values,
        bool $transaction = true,
    ): void {
        $callback = function () use ($record, $action, $actor, $reasonCode, $outcome, $values): void {
            $values['lease_owner'] = null;
            $values['lease_expires_at'] = null;
            $values['updated_at'] = $this->timestamp();
            if ($this->database()->table($this->intentsTable)->where('operation_id', $record->operationId)
                ->where('status', $record->status->value)->where('stage', $record->stage->value)
                ->where('lease_generation', $record->leaseGeneration)
                ->where('last_error_code', $record->lastErrorCode)->update($values) !== 1) {
                throw new RuntimeException('The subscription recovery request is stale.');
            }
            $this->database()->table($this->auditsTable)->insert([
                'operation_id' => $record->operationId, 'action' => $action, 'actor' => $actor,
                'reason_code' => $reasonCode, 'prior_status' => $record->status->value,
                'prior_stage' => $record->stage->value, 'prior_error_code' => $record->lastErrorCode,
                'outcome' => $outcome, 'created_at' => $this->clock->now(),
            ]);
        };
        $transaction ? $this->database()->transaction($callback) : $callback();
    }

    private function owned(SubscriptionClaim $claim): Builder
    {
        return $this->database()->table($this->intentsTable)->where('operation_id', $claim->record->operationId)
            ->where('status', SubscriptionStatus::Processing->value)->where('lease_owner', $claim->owner)
            ->where('lease_generation', $claim->generation)->where('lease_expires_at', $claim->expiresAt)
            ->where('lease_expires_at', '>', $this->clock->now());
    }

    /** @param array<string, mixed> $values */
    private function transition(SubscriptionClaim $claim, array $values): void
    {
        $values['updated_at'] = $this->timestamp();
        if ($this->owned($claim)->update($values) !== 1) {
            throw new RuntimeException('The subscription delivery reservation was lost.');
        }
    }

    private function exactProvisioning(
        string $hash,
        string $operationId,
        string $state,
        string $owner,
        int $generation,
        int $expiresAt,
    ): Builder {
        return $this->database()->table($this->locksTable)->where('segment_key_hash', $hash)
            ->where('operation_id', $operationId)->where('state', $state)
            ->where('owner', $owner)->where('generation', $generation)
            ->where('expires_at', $expiresAt);
    }

    private function ownedProvisioning(SegmentProvisioningClaim $claim): Builder
    {
        return $this->database()->table($this->locksTable)
            ->where('segment_key_hash', self::segmentHash($claim->connection, $claim->segmentKey))
            ->where('connection', $claim->connection)->where('segment_key', $claim->segmentKey)
            ->where('operation_id', $claim->operationId)->where('owner', $claim->owner)
            ->where('generation', $claim->generation)->where('expires_at', $claim->expiresAt)
            ->where('expires_at', '>', $this->clock->now());
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
