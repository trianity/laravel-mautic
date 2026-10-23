<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Subscription;

use RuntimeException;
use Throwable;
use Trianity\LaravelMautic\Contracts\ContactMappingRepository;
use Trianity\LaravelMautic\Contracts\SubscriptionTransport;
use Trianity\LaravelMautic\Exceptions\AmbiguousMutationOutcome;
use Trianity\LaravelMautic\Exceptions\MauticReadException;
use Trianity\LaravelMautic\Exceptions\MauticWriteException;
use Trianity\LaravelMautic\Exceptions\SegmentConflict;
use Trianity\LaravelMautic\Exceptions\SegmentCreateEvidence;
use Trianity\LaravelMautic\Exceptions\SegmentNotFound;
use Trianity\LaravelMautic\Identity\RemoteContactState;
use Trianity\LaravelMautic\Outbox\DatabaseOutboxStore;
use Trianity\LaravelMautic\Outbox\OutboxStatus;
use Trianity\LaravelMautic\Read\ReadFailureKind;
use Trianity\LaravelMautic\Segment\StaticSegment;
use Trianity\LaravelMautic\Write\FailureKind;

final readonly class SubscriptionProcessor
{
    public function __construct(
        private DatabaseSubscriptionStore $store,
        private DatabaseOutboxStore $outbox,
        private ContactMappingRepository $mappings,
        private SubscriptionTransport $transport,
        private int $leaseSeconds = 30,
        private int $maxMembershipAttempts = 5,
        private int $maximumDelaySeconds = 300,
    ) {
        if ($leaseSeconds < 1 || $maxMembershipAttempts < 1 || $maximumDelaySeconds < 1) {
            throw new \InvalidArgumentException('Subscription lease, attempt and delay limits must be positive.');
        }
    }

    public function process(string $operationId): void
    {
        $owner = bin2hex(random_bytes(16));
        $claim = $this->store->begin($operationId, $owner, $this->leaseSeconds);
        if ($claim === null) {
            return;
        }
        $record = $claim->record;

        $contactOutbox = $this->outbox->get($record->contactOperationId);
        if ($contactOutbox === null) {
            $this->store->stop($claim, SubscriptionStatus::Blocked, 'contact_command_missing');

            return;
        }
        if ($contactOutbox->status !== OutboxStatus::Completed) {
            if ($contactOutbox->status->terminal()) {
                $status = $contactOutbox->status === OutboxStatus::Ambiguous
                    ? SubscriptionStatus::Ambiguous : SubscriptionStatus::Blocked;
                $this->store->stop($claim, $status, 'contact_'.$contactOutbox->status->value);
            } else {
                $this->store->pending($claim, SubscriptionStage::Recorded);
            }

            return;
        }
        $mapping = $this->mappings->find($record->connection, $record->localReference);
        if ($mapping === null || $mapping->remoteState !== RemoteContactState::Active) {
            $this->store->stop($claim, SubscriptionStatus::Blocked, 'active_contact_mapping_missing');

            return;
        }
        $contactId = $mapping->remoteId;

        $segmentClaim = $this->store->acquireSegment(
            $record->connection,
            $record->segment->key,
            $record->operationId,
            $owner,
            $this->leaseSeconds,
        );
        if ($segmentClaim === null) {
            $block = $this->store->segmentProvisioningBlock($record->connection, $record->segment->key);
            if (($block->state ?? null) === 'ambiguous') {
                $this->store->stop($claim, SubscriptionStatus::Ambiguous, 'segment_key_provisioning_ambiguous');
            } else {
                $this->store->pending($claim, SubscriptionStage::ContactReady, $contactId);
            }

            return;
        }

        try {
            $segmentId = $record->remoteSegmentId ?? $this->resolveSegment($claim, $segmentClaim, $contactId);
            $this->store->completeSegmentProvisioning($segmentClaim);
        } catch (AmbiguousMutationOutcome) {
            $this->store->preserveSegmentCreateEvidence($segmentClaim, null, 'segment_create_outcome_ambiguous');
            $this->store->stop($claim, SubscriptionStatus::Ambiguous, 'segment_create_outcome_ambiguous');

            return;
        } catch (SegmentCreateEvidence $exception) {
            $stored = $this->store->preserveSegmentCreateEvidence(
                $segmentClaim,
                $exception->remoteId,
                'segment_create_evidence_preserved',
            );
            $this->store->stop(
                $claim,
                SubscriptionStatus::Ambiguous,
                $stored ? 'segment_create_requires_recovery' : 'segment_create_evidence_persistence_failed',
                $stored ? $exception->remoteId : null,
            );

            return;
        } catch (SegmentConflict $exception) {
            $this->store->releaseSegment($segmentClaim);
            $this->store->stop($claim, SubscriptionStatus::Blocked, $exception->getMessage());

            return;
        } catch (SegmentNotFound) {
            $this->store->releaseSegment($segmentClaim);
            $this->store->stop($claim, SubscriptionStatus::Blocked, 'segment_missing');

            return;
        } catch (Throwable) {
            $block = $this->store->segmentProvisioningBlock($record->connection, $record->segment->key);
            if ($block !== null && ($block->operation_id ?? null) === $record->operationId
                && in_array(($block->state ?? null), ['create_in_flight', 'ambiguous'], true)) {
                $remoteId = is_numeric($block->remote_id ?? null) ? (int) $block->remote_id : null;
                $stored = $this->store->preserveSegmentCreateEvidence(
                    $segmentClaim,
                    $remoteId,
                    'segment_create_local_completion_failed',
                );
                $this->store->stop(
                    $claim,
                    SubscriptionStatus::Ambiguous,
                    $stored ? 'segment_create_requires_recovery' : 'segment_create_evidence_persistence_failed',
                    $stored ? $remoteId : null,
                );

                return;
            }
            $this->store->releaseSegment($segmentClaim);
            $this->store->stop($claim, SubscriptionStatus::Failed, 'segment_resolution_failed');

            return;
        }

        $this->store->segmentReady($claim, $contactId, $segmentId);
        try {
            $this->store->assertOwned($claim);
            if ($this->hasMembership($record->connection, $contactId, $segmentId)) {
                $this->store->complete($claim);

                return;
            }
        } catch (MauticReadException $exception) {
            $this->handleMembershipPreflightFailure($claim, $segmentId, $exception);

            return;
        } catch (Throwable) {
            $this->store->rejectMembership(
                $claim,
                ReadFailureKind::UnexpectedResponse->value,
                'membership_preflight_unexpected_response',
                $segmentId,
            );

            return;
        }

        $this->store->membershipInFlight($claim);
        try {
            $this->store->assertOwned($claim);
            $this->transport->addMembership($record->connection, $contactId, $segmentId);
        } catch (AmbiguousMutationOutcome) {
            $this->store->stop(
                $claim,
                SubscriptionStatus::Ambiguous,
                'membership_outcome_ambiguous',
                $segmentId,
                FailureKind::AmbiguousOutcome->value,
            );

            return;
        } catch (MauticWriteException $exception) {
            $this->handleMembershipMutationFailure($claim, $segmentId, $exception);

            return;
        } catch (Throwable) {
            $this->store->stop(
                $claim,
                SubscriptionStatus::Ambiguous,
                'membership_mutation_unexpected_response',
                $segmentId,
                FailureKind::UnexpectedResponse->value,
            );

            return;
        }

        try {
            if (! $this->hasMembership($record->connection, $contactId, $segmentId)) {
                $this->store->stop(
                    $claim,
                    SubscriptionStatus::Ambiguous,
                    'membership_not_verified',
                    $segmentId,
                    ReadFailureKind::UnexpectedResponse->value,
                );

                return;
            }
            $this->store->complete($claim);
        } catch (MauticReadException $exception) {
            $this->store->stop(
                $claim,
                SubscriptionStatus::Ambiguous,
                'membership_verification_'.$exception->kind->value,
                $segmentId,
                $exception->kind->value,
            );
        } catch (Throwable) {
            $this->store->stop(
                $claim,
                SubscriptionStatus::Ambiguous,
                'membership_verification_unexpected_response',
                $segmentId,
                ReadFailureKind::UnexpectedResponse->value,
            );
        }
    }

    private function handleMembershipPreflightFailure(
        SubscriptionClaim $claim,
        int $segmentId,
        MauticReadException $exception,
    ): void {
        $attempt = $claim->record->membershipReadAttempts + 1;
        $retryable = in_array($exception->kind, [
            ReadFailureKind::Authentication,
            ReadFailureKind::Timeout,
            ReadFailureKind::Throttled,
            ReadFailureKind::RemoteServer,
            ReadFailureKind::Transport,
        ], true);
        if ($retryable && $attempt < $this->maxMembershipAttempts) {
            $this->store->retryMembershipRead(
                $claim,
                $this->nextAttemptAt($claim->record->operationId, $attempt, $exception->retryAfterSeconds),
                $exception->kind->value,
                'membership_preflight_'.$exception->kind->value,
            );

            return;
        }
        $this->store->rejectMembership(
            $claim,
            $exception->kind->value,
            $retryable ? 'membership_preflight_attempts_exhausted' : 'membership_preflight_'.$exception->kind->value,
            $segmentId,
            true,
        );
    }

    private function handleMembershipMutationFailure(
        SubscriptionClaim $claim,
        int $segmentId,
        MauticWriteException $exception,
    ): void {
        $attempt = $claim->record->membershipWriteAttempts + 1;
        if (in_array($exception->kind, [FailureKind::Authentication, FailureKind::Throttled], true)
            && $attempt < $this->maxMembershipAttempts) {
            $this->store->retryMembershipWrite(
                $claim,
                $this->nextAttemptAt($claim->record->operationId, $attempt, $exception->retryAfterSeconds),
                $exception->kind->value,
                'membership_rejected_'.$exception->kind->value,
            );

            return;
        }
        if (in_array($exception->kind, [
            FailureKind::Authentication,
            FailureKind::Validation,
            FailureKind::Throttled,
            FailureKind::TargetNotFound,
        ], true)) {
            $this->store->rejectMembership(
                $claim,
                $exception->kind->value,
                'membership_rejected_'.$exception->kind->value,
                $segmentId,
            );

            return;
        }
        $this->store->stop(
            $claim,
            SubscriptionStatus::Ambiguous,
            'membership_mutation_'.$exception->kind->value,
            $segmentId,
            $exception->kind->value,
        );
    }

    private function nextAttemptAt(string $operationId, int $attempt, ?int $retryAfter): int
    {
        $delay = $retryAfter === null
            ? min($this->maximumDelaySeconds, (5 * (2 ** max(0, $attempt - 1))) + (crc32($operationId) % 3))
            : max(1, $retryAfter);

        return $this->store->now() + $delay;
    }

    private function resolveSegment(
        SubscriptionClaim $subscriptionClaim,
        SegmentProvisioningClaim $provisioningClaim,
        int $contactId,
    ): int {
        $record = $subscriptionClaim->record;
        $this->store->assertOwned($subscriptionClaim);
        $gateway = $this->transport->segments($record->connection);
        $associated = $this->store->associatedSegment($record->connection, $record->segment->key);
        if ($associated !== null) {
            $segment = $gateway->find($associated);
            $this->assertSuitable($record, $segment);

            return $associated;
        }
        if ($record->segment->remoteId !== null) {
            $segment = $gateway->find($record->segment->remoteId);
            $this->assertSuitable($record, $segment);
            $this->store->associateSegment($record->connection, $record->segment->key, $segment->remoteId, $this->definitionHash($segment));

            return $segment->remoteId;
        }
        $definition = $record->segment->definition;
        if ($definition === null) {
            throw new SegmentConflict('segment_association_missing');
        }
        $candidates = $gateway->candidates($definition->alias);
        if (count($candidates) > 1) {
            throw new SegmentConflict('segment_candidates_ambiguous');
        }
        if ($candidates === []) {
            if ($record->segment->policy === SegmentProvisioningPolicy::ExistingOnly) {
                throw new SegmentConflict('segment_missing');
            }
            $this->store->segmentCreateInFlight($provisioningClaim);
            $this->store->segmentProvisioningInFlight($subscriptionClaim, $contactId);
            $this->store->assertOwned($subscriptionClaim);
            $segment = $gateway->create($definition);
            try {
                $this->store->recordSegmentCreateEvidence($provisioningClaim, $segment->remoteId);
            } catch (Throwable $exception) {
                if (! $this->store->preserveSegmentCreateEvidence(
                    $provisioningClaim,
                    $segment->remoteId,
                    'segment_create_evidence_fallback',
                )) {
                    throw new RuntimeException(
                        'The known remote segment ID could not be persisted; the durable create marker remains blocking.',
                        previous: $exception,
                    );
                }
                throw new SegmentCreateEvidence($segment->remoteId, previous: $exception);
            }
        } else {
            $segment = $candidates[0];
        }
        $this->assertSuitable($record, $segment);
        try {
            $this->store->associateSegment($record->connection, $record->segment->key, $segment->remoteId, $this->definitionHash($segment));
        } catch (Throwable $exception) {
            if ($candidates === []) {
                throw new SegmentCreateEvidence($segment->remoteId, previous: $exception);
            }
            throw $exception;
        }

        return $segment->remoteId;
    }

    private function assertSuitable(SubscriptionRecord $record, StaticSegment $segment): void
    {
        if (! $segment->suitable()) {
            throw new SegmentConflict('segment_not_published_static');
        }
        if ($record->segment->remoteId !== null && $segment->remoteId !== $record->segment->remoteId) {
            throw new SegmentConflict('segment_remote_id_conflict');
        }
        $definition = $record->segment->definition;
        if ($definition !== null && ($segment->alias !== $definition->alias
            || $segment->name !== $definition->name || $segment->global !== $definition->global)) {
            throw new SegmentConflict('segment_definition_conflict');
        }
    }

    /** @phpstan-impure */
    private function hasMembership(string $connection, int $contactId, int $segmentId): bool
    {
        foreach ($this->transport->memberships($connection, $contactId)->memberships as $membership) {
            if ($membership->remoteId === $segmentId) {
                return true;
            }
        }

        return false;
    }

    private function definitionHash(StaticSegment $segment): string
    {
        return hash('sha256', json_encode([
            $segment->name, $segment->alias, $segment->published, $segment->global, $segment->filters,
        ], JSON_THROW_ON_ERROR));
    }
}
