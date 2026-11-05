<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Subscription;

use RuntimeException;
use Throwable;
use Trianity\LaravelMautic\Contracts\ContactMappingRepository;
use Trianity\LaravelMautic\Contracts\MembershipPlanTransport;
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

        if ($this->store->hasCompletedSuccessor($record)) {
            $this->store->supersede($claim);

            return;
        }
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

        $planItems = $this->store->planItems($record->operationId);
        if ($planItems !== []) {
            if ($this->store->hasUnresolvedPredecessor($record)) {
                $this->store->pending($claim, SubscriptionStage::ContactReady, $contactId);

                return;
            }
            $this->processPlan($claim, $contactId, $planItems, $owner);

            return;
        }

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

        if ($this->store->hasUnresolvedPredecessor($record)) {
            $this->store->releaseSegment($segmentClaim);
            $this->store->pending($claim, SubscriptionStage::ContactReady, $contactId);

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

    /** @param list<MembershipPlanItem> $items */
    private function processPlan(SubscriptionClaim $claim, int $contactId, array $items, string $owner): void
    {
        if (! $this->transport instanceof MembershipPlanTransport) {
            $this->store->stop($claim, SubscriptionStatus::Blocked, 'membership_plan_transport_unavailable');

            return;
        }

        $record = $claim->record;
        foreach ($items as $item) {
            if ($item->status === 'satisfied' || $item->remoteSegmentId !== null) {
                continue;
            }
            $target = $item->requirement->target;
            $segmentClaim = $this->store->acquireSegment(
                $record->connection,
                $target->key,
                $record->operationId,
                $owner,
                $this->leaseSeconds,
            );
            if ($segmentClaim === null) {
                $block = $this->store->segmentProvisioningBlock($record->connection, $target->key);
                if (($block->state ?? null) === 'ambiguous') {
                    $this->store->stop($claim, SubscriptionStatus::Ambiguous, 'segment_key_provisioning_ambiguous');
                } else {
                    $this->store->pending($claim, SubscriptionStage::ContactReady, $contactId);
                }

                return;
            }
            try {
                $segmentId = $this->resolveSegment($claim, $segmentClaim, $contactId, $target);
                $this->store->completeSegmentProvisioning($segmentClaim);
                $this->store->resolvePlanItem($claim, $item->position, $segmentId);
            } catch (AmbiguousMutationOutcome) {
                $this->store->preserveSegmentCreateEvidence($segmentClaim, null, 'segment_create_outcome_ambiguous');
                $this->store->stop($claim, SubscriptionStatus::Ambiguous, 'segment_create_outcome_ambiguous');

                return;
            } catch (SegmentCreateEvidence $exception) {
                $this->store->preserveSegmentCreateEvidence($segmentClaim, $exception->remoteId, 'segment_create_evidence_preserved');
                $this->store->stop($claim, SubscriptionStatus::Ambiguous, 'segment_create_requires_recovery', $exception->remoteId);

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
                $block = $this->store->segmentProvisioningBlock($record->connection, $target->key);
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
        }

        $items = $this->store->planItems($record->operationId);
        $resolvedPresence = [];
        foreach ($items as $item) {
            if ($item->remoteSegmentId === null) {
                $this->store->stop($claim, SubscriptionStatus::Blocked, 'membership_plan_target_unresolved');

                return;
            }
            $presence = $item->requirement->presence;
            if (isset($resolvedPresence[$item->remoteSegmentId]) && $resolvedPresence[$item->remoteSegmentId] !== $presence) {
                $this->store->stop($claim, SubscriptionStatus::Blocked, 'membership_plan_remote_target_conflict');

                return;
            }
            $resolvedPresence[$item->remoteSegmentId] = $presence;
        }
        $this->store->segmentReady($claim, $contactId, $items[0]->remoteSegmentId ?? 0);

        try {
            $current = $this->membershipIds($record->connection, $contactId);
        } catch (MauticReadException $exception) {
            $this->handleMembershipPreflightFailure($claim, $items[0]->remoteSegmentId ?? 0, $exception);

            return;
        } catch (Throwable) {
            $this->store->rejectMembership($claim, ReadFailureKind::UnexpectedResponse->value, 'membership_preflight_unexpected_response', $items[0]->remoteSegmentId ?? 0);

            return;
        }

        foreach ($items as $item) {
            $segmentId = $item->remoteSegmentId;
            if ($segmentId === null) {
                continue;
            }
            $present = isset($current[$segmentId]);
            if (($item->requirement->presence === MembershipPresence::Present) === $present) {
                if ($item->status !== 'satisfied') {
                    $this->store->satisfyPlanItem($claim, $item->position);
                }
            }
        }

        $items = $this->store->planItems($record->operationId);
        usort($items, static function (MembershipPlanItem $left, MembershipPlanItem $right): int {
            $leftOrder = $left->requirement->presence === MembershipPresence::Absent ? 0 : 1;
            $rightOrder = $right->requirement->presence === MembershipPresence::Absent ? 0 : 1;

            return $leftOrder <=> $rightOrder ?: $left->position <=> $right->position;
        });
        foreach ($items as $item) {
            if ($item->status === 'satisfied') {
                continue;
            }
            if ($item->status === 'in_flight' || $item->status === 'ambiguous') {
                $this->store->stop($claim, SubscriptionStatus::Ambiguous, 'membership_plan_item_requires_recovery', $item->remoteSegmentId);

                return;
            }
            $segmentId = $item->remoteSegmentId;
            if ($segmentId === null) {
                $this->store->stop($claim, SubscriptionStatus::Blocked, 'membership_plan_target_unresolved');

                return;
            }
            $this->store->planItemInFlight($claim, $item);
            try {
                $this->store->assertOwned($claim);
                if ($item->requirement->presence === MembershipPresence::Present) {
                    $this->transport->addMembership($record->connection, $contactId, $segmentId);
                } else {
                    $this->transport->removeMembership($record->connection, $contactId, $segmentId);
                }
            } catch (AmbiguousMutationOutcome) {
                $this->store->failPlanItem($claim, $item->position, 'ambiguous', 'membership_outcome_ambiguous');
                $this->store->stop($claim, SubscriptionStatus::Ambiguous, 'membership_outcome_ambiguous', $segmentId, FailureKind::AmbiguousOutcome->value);

                return;
            } catch (MauticWriteException $exception) {
                if (in_array($exception->kind, [
                    FailureKind::Authentication,
                    FailureKind::Validation,
                    FailureKind::Throttled,
                    FailureKind::TargetNotFound,
                ], true)) {
                    $this->store->resetPlanItem($claim, $item->position, 'membership_rejected_'.$exception->kind->value);
                } else {
                    $this->store->failPlanItem($claim, $item->position, 'ambiguous', 'membership_mutation_'.$exception->kind->value);
                }
                $this->handleMembershipMutationFailure($claim, $segmentId, $exception);

                return;
            } catch (Throwable) {
                $this->store->failPlanItem($claim, $item->position, 'ambiguous', 'membership_mutation_unexpected_response');
                $this->store->stop($claim, SubscriptionStatus::Ambiguous, 'membership_mutation_unexpected_response', $segmentId, FailureKind::UnexpectedResponse->value);

                return;
            }

            try {
                $after = $this->membershipIds($record->connection, $contactId);
                $present = isset($after[$segmentId]);
                if (($item->requirement->presence === MembershipPresence::Present) !== $present) {
                    $this->store->failPlanItem($claim, $item->position, 'ambiguous', 'membership_not_verified');
                    $this->store->stop($claim, SubscriptionStatus::Ambiguous, 'membership_not_verified', $segmentId, ReadFailureKind::UnexpectedResponse->value);

                    return;
                }
                $this->satisfyEquivalentPlanItems($claim, $segmentId, $item->requirement->presence);
            } catch (Throwable $exception) {
                $kind = $exception instanceof MauticReadException ? $exception->kind->value : ReadFailureKind::UnexpectedResponse->value;
                $this->store->failPlanItem($claim, $item->position, 'ambiguous', 'membership_verification_'.$kind);
                $this->store->stop($claim, SubscriptionStatus::Ambiguous, 'membership_verification_'.$kind, $segmentId, $kind);

                return;
            }
        }

        try {
            $final = $this->membershipIds($record->connection, $contactId);
            foreach ($this->store->planItems($record->operationId) as $item) {
                $present = $item->remoteSegmentId !== null && isset($final[$item->remoteSegmentId]);
                if (($item->requirement->presence === MembershipPresence::Present) !== $present) {
                    $this->store->stop($claim, SubscriptionStatus::Ambiguous, 'membership_plan_final_verification_failed', $item->remoteSegmentId);

                    return;
                }
            }
            $this->store->complete($claim);
        } catch (Throwable $exception) {
            $kind = $exception instanceof MauticReadException ? $exception->kind->value : ReadFailureKind::UnexpectedResponse->value;
            $this->store->stop($claim, SubscriptionStatus::Ambiguous, 'membership_plan_final_verification_'.$kind, failureKind: $kind);
        }
    }

    /** @return array<int, true> */
    private function membershipIds(string $connection, int $contactId): array
    {
        $ids = [];
        foreach ($this->transport->memberships($connection, $contactId)->memberships as $membership) {
            $ids[$membership->remoteId] = true;
        }

        return $ids;
    }

    private function satisfyEquivalentPlanItems(
        SubscriptionClaim $claim,
        int $segmentId,
        MembershipPresence $presence,
    ): void {
        foreach ($this->store->planItems($claim->record->operationId) as $item) {
            if ($item->status !== 'satisfied' && $item->remoteSegmentId === $segmentId
                && $item->requirement->presence === $presence) {
                $this->store->satisfyPlanItem($claim, $item->position);
            }
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
        ?SegmentTarget $target = null,
    ): int {
        $record = $subscriptionClaim->record;
        $target ??= $record->segment;
        $this->store->assertOwned($subscriptionClaim);
        $gateway = $this->transport->segments($record->connection);
        $associated = $this->store->associatedSegment($record->connection, $target->key);
        if ($associated !== null) {
            $segment = $gateway->find($associated);
            $this->assertSuitable($target, $segment);

            return $associated;
        }
        if ($target->remoteId !== null) {
            $segment = $gateway->find($target->remoteId);
            $this->assertSuitable($target, $segment);
            $this->store->associateSegment($record->connection, $target->key, $segment->remoteId, $this->definitionHash($segment));

            return $segment->remoteId;
        }
        $definition = $target->definition;
        if ($definition === null) {
            throw new SegmentConflict('segment_association_missing');
        }
        $candidates = $gateway->candidates($definition->alias);
        if (count($candidates) > 1) {
            throw new SegmentConflict('segment_candidates_ambiguous');
        }
        if ($candidates === []) {
            if ($target->policy === SegmentProvisioningPolicy::ExistingOnly) {
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
        $this->assertSuitable($target, $segment);
        try {
            $this->store->associateSegment($record->connection, $target->key, $segment->remoteId, $this->definitionHash($segment));
        } catch (Throwable $exception) {
            if ($candidates === []) {
                throw new SegmentCreateEvidence($segment->remoteId, previous: $exception);
            }
            throw $exception;
        }

        return $segment->remoteId;
    }

    private function assertSuitable(SegmentTarget $target, StaticSegment $segment): void
    {
        if (! $segment->suitable()) {
            throw new SegmentConflict('segment_not_published_static');
        }
        if ($target->remoteId !== null && $segment->remoteId !== $target->remoteId) {
            throw new SegmentConflict('segment_remote_id_conflict');
        }
        $definition = $target->definition;
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
