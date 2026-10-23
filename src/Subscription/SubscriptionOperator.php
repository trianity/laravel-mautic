<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Subscription;

use RuntimeException;
use Trianity\LaravelMautic\Contracts\ContactMappingRepository;
use Trianity\LaravelMautic\Contracts\SubscriptionTransport;
use Trianity\LaravelMautic\Exceptions\SegmentConflict;
use Trianity\LaravelMautic\Identity\RemoteContactState;
use Trianity\LaravelMautic\Outbox\DatabaseOutboxStore;
use Trianity\LaravelMautic\Outbox\OutboxStatus;
use Trianity\LaravelMautic\Segment\StaticSegment;

final readonly class SubscriptionOperator
{
    public function __construct(
        private DatabaseSubscriptionStore $store,
        private SubscriptionTransport $transport,
        private DatabaseOutboxStore $outbox,
        private ContactMappingRepository $mappings,
    ) {}

    public function resumeContactPrerequisite(string $operationId, string $actor, string $reasonCode): void
    {
        $this->validateAudit($actor, $reasonCode);
        $record = $this->required($operationId);
        $contact = $this->outbox->get($record->contactOperationId);
        $mapping = $this->mappings->find($record->connection, $record->localReference);
        if ($contact === null || $contact->status !== OutboxStatus::Completed || $mapping === null
            || $mapping->remoteState !== RemoteContactState::Active
            || ($contact->remoteId !== null && $contact->remoteId !== $mapping->remoteId)) {
            throw new RuntimeException('Contact recovery requires a completed exact outbox prerequisite and active mapping.');
        }
        $this->store->operatorResumeContact($operationId, $mapping->remoteId, $actor, $reasonCode);
    }

    public function resumeMembershipFailure(string $operationId, string $actor, string $reasonCode): void
    {
        $this->validateAudit($actor, $reasonCode);
        $record = $this->required($operationId);
        if ($record->status !== SubscriptionStatus::Failed || $record->lastErrorCode !== 'membership_write_failed') {
            throw new RuntimeException('Only a definite membership write failure may be resumed without attestation.');
        }
        $this->store->operatorReplayMembership($operationId, $actor, $reasonCode);
    }

    public function confirmMembership(string $operationId, string $actor, string $reasonCode): void
    {
        $this->validateAudit($actor, $reasonCode);
        $record = $this->required($operationId);
        if ($record->remoteContactId === null || $record->remoteSegmentId === null) {
            throw new RuntimeException('Membership recovery requires stored remote contact and segment identities.');
        }
        foreach ($this->transport->memberships($record->connection, $record->remoteContactId)->memberships as $membership) {
            if ($membership->remoteId === $record->remoteSegmentId) {
                $this->store->operatorCompleteMembership($operationId, $actor, $reasonCode);

                return;
            }
        }
        throw new RuntimeException('The exact remote membership is not currently present; no mutation was replayed.');
    }

    public function authorizeMembershipReplay(
        string $operationId,
        string $actor,
        string $reasonCode,
        bool $previousRequestCannotComplete,
    ): void {
        $this->validateAudit($actor, $reasonCode);
        if (! $previousRequestCannotComplete) {
            throw new RuntimeException('Recovery requires trusted attestation that the previous membership request cannot complete.');
        }
        $this->store->operatorReplayMembership($operationId, $actor, $reasonCode);
    }

    public function associateAmbiguousCreate(
        string $operationId,
        int $remoteId,
        string $actor,
        string $reasonCode,
        bool $previousRequestCannotCreateAnotherSegment,
    ): void {
        $this->validateAudit($actor, $reasonCode);
        if (! $previousRequestCannotCreateAnotherSegment) {
            throw new RuntimeException('Recovery requires attestation that the previous create cannot later create another segment.');
        }
        $record = $this->required($operationId);
        $block = $this->store->segmentProvisioningBlock($record->connection, $record->segment->key);
        if ($block === null || ! in_array(($block->state ?? null), ['ambiguous', 'create_in_flight'], true)) {
            throw new RuntimeException('The exact segment-key provisioning blocker no longer exists.');
        }
        if (($block->remote_id ?? null) !== null && (int) $block->remote_id !== $remoteId) {
            throw new SegmentConflict('The supplied segment ID conflicts with retained create evidence.');
        }
        $segment = $this->transport->segments($record->connection)->find($remoteId);
        $this->assertSuitable($record, $segment);
        $this->store->operatorAssociateSegment(
            $operationId,
            $remoteId,
            $this->definitionHash($segment),
            $actor,
            $reasonCode,
        );
    }

    public function resumeSegmentPrerequisite(string $operationId, string $actor, string $reasonCode): void
    {
        $this->validateAudit($actor, $reasonCode);
        $record = $this->required($operationId);
        $remoteId = $this->store->associatedSegment($record->connection, $record->segment->key)
            ?? throw new RuntimeException('The segment prerequisite has no verified stored association.');
        $segment = $this->transport->segments($record->connection)->find($remoteId);
        $this->assertSuitable($record, $segment);
        $this->store->operatorResumeSegmentPrerequisite(
            $operationId,
            $remoteId,
            $actor,
            $reasonCode,
        );
    }

    private function required(string $operationId): SubscriptionRecord
    {
        $record = $this->store->get($operationId);
        if ($record === null) {
            throw new RuntimeException('The subscription operation does not exist.');
        }

        return $record;
    }

    private function assertSuitable(SubscriptionRecord $record, StaticSegment $segment): void
    {
        $definition = $record->segment->definition;
        if (! $segment->suitable()
            || ($record->segment->remoteId !== null && $record->segment->remoteId !== $segment->remoteId)
            || ($definition !== null && ($definition->name !== $segment->name
                || $definition->alias !== $segment->alias || $definition->global !== $segment->global))) {
            throw new SegmentConflict('The verified remote segment does not match the stored subscription target.');
        }
    }

    private function definitionHash(StaticSegment $segment): string
    {
        return hash('sha256', json_encode([
            $segment->name, $segment->alias, $segment->published, $segment->global, $segment->filters,
        ], JSON_THROW_ON_ERROR));
    }

    private function validateAudit(string $actor, string $reasonCode): void
    {
        if (trim($actor) === '' || strlen($actor) > 64 || trim($reasonCode) === '' || strlen($reasonCode) > 64) {
            throw new RuntimeException('Operator actor and reason code are required bounded audit values.');
        }
    }
}
