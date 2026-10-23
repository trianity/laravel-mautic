<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Subscription;

use Throwable;
use Trianity\LaravelMautic\Contracts\ContactMappingRepository;
use Trianity\LaravelMautic\Contracts\SubscriptionTransport;
use Trianity\LaravelMautic\Exceptions\AmbiguousMutationOutcome;
use Trianity\LaravelMautic\Exceptions\MauticWriteException;
use Trianity\LaravelMautic\Exceptions\SegmentConflict;
use Trianity\LaravelMautic\Exceptions\SegmentNotFound;
use Trianity\LaravelMautic\Identity\RemoteContactState;
use Trianity\LaravelMautic\Outbox\DatabaseOutboxStore;
use Trianity\LaravelMautic\Outbox\OutboxStatus;
use Trianity\LaravelMautic\Segment\StaticSegment;

final readonly class SubscriptionProcessor
{
    public function __construct(
        private DatabaseSubscriptionStore $store,
        private DatabaseOutboxStore $outbox,
        private ContactMappingRepository $mappings,
        private SubscriptionTransport $transport,
        private int $leaseSeconds = 30,
    ) {}

    public function process(string $operationId): void
    {
        $owner = bin2hex(random_bytes(16));
        $record = $this->store->begin($operationId, $owner, $this->leaseSeconds);
        if ($record === null) {
            return;
        }

        $contactOutbox = $this->outbox->get($record->contactOperationId);
        if ($contactOutbox === null) {
            $this->store->stop($operationId, $owner, SubscriptionStatus::Blocked, 'contact_command_missing');

            return;
        }
        if ($contactOutbox->status !== OutboxStatus::Completed) {
            if ($contactOutbox->status->terminal()) {
                $status = $contactOutbox->status === OutboxStatus::Ambiguous
                    ? SubscriptionStatus::Ambiguous : SubscriptionStatus::Blocked;
                $this->store->stop($operationId, $owner, $status, 'contact_'.$contactOutbox->status->value);
            } else {
                $this->store->pending($operationId, $owner, SubscriptionStage::Recorded);
            }

            return;
        }
        $mapping = $this->mappings->find($record->connection, $record->localReference);
        if ($mapping === null || $mapping->remoteState !== RemoteContactState::Active) {
            $this->store->stop($operationId, $owner, SubscriptionStatus::Blocked, 'active_contact_mapping_missing');

            return;
        }
        $contactId = $mapping->remoteId;

        if (! $this->store->acquireSegment($record->connection, $record->segment->key, $owner, $this->leaseSeconds)) {
            $this->store->pending($operationId, $owner, SubscriptionStage::ContactReady, $contactId);

            return;
        }

        try {
            $this->store->segmentProvisioningInFlight($operationId, $owner, $contactId);
            $segmentId = $record->remoteSegmentId ?? $this->resolveSegment($record);
        } catch (AmbiguousMutationOutcome) {
            $this->store->stop($operationId, $owner, SubscriptionStatus::Ambiguous, 'segment_create_outcome_ambiguous');

            return;
        } catch (SegmentConflict $exception) {
            $this->store->stop($operationId, $owner, SubscriptionStatus::Blocked, $exception->getMessage());

            return;
        } catch (SegmentNotFound) {
            $this->store->stop($operationId, $owner, SubscriptionStatus::Blocked, 'segment_missing');

            return;
        } catch (Throwable) {
            $this->store->stop($operationId, $owner, SubscriptionStatus::Failed, 'segment_resolution_failed');

            return;
        } finally {
            $this->store->releaseSegment($record->connection, $record->segment->key, $owner);
        }

        $this->store->segmentReady($operationId, $owner, $contactId, $segmentId);
        try {
            if ($this->hasMembership($record->connection, $contactId, $segmentId)) {
                $this->store->complete($operationId, $owner);

                return;
            }
            $this->store->membershipInFlight($operationId, $owner);
            $this->transport->addMembership($record->connection, $contactId, $segmentId);
            if (! $this->hasMembership($record->connection, $contactId, $segmentId)) {
                $this->store->stop($operationId, $owner, SubscriptionStatus::Ambiguous, 'membership_not_verified', $segmentId);

                return;
            }
            $this->store->complete($operationId, $owner);
        } catch (AmbiguousMutationOutcome) {
            $this->store->stop($operationId, $owner, SubscriptionStatus::Ambiguous, 'membership_outcome_ambiguous', $segmentId);
        } catch (MauticWriteException) {
            $this->store->stop($operationId, $owner, SubscriptionStatus::Failed, 'membership_write_failed', $segmentId);
        } catch (Throwable) {
            $this->store->stop($operationId, $owner, SubscriptionStatus::Ambiguous, 'membership_verification_uncertain', $segmentId);
        }
    }

    private function resolveSegment(SubscriptionRecord $record): int
    {
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
            $segment = $gateway->create($definition);
        } else {
            $segment = $candidates[0];
        }
        $this->assertSuitable($record, $segment);
        $this->store->associateSegment($record->connection, $record->segment->key, $segment->remoteId, $this->definitionHash($segment));

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
