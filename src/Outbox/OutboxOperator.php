<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Outbox;

use RuntimeException;
use Trianity\LaravelMautic\Contracts\ContactMappingRepository;
use Trianity\LaravelMautic\Identity\RemoteContactState;

final readonly class OutboxOperator
{
    public function __construct(
        private DatabaseOutboxStore $store,
        private ContactMappingRepository $mappings,
    ) {}

    public function reconcileCreated(string $operationId, int $remoteId, string $actor, string $reasonCode): void
    {
        $this->validateAudit($actor, $reasonCode);
        $record = $this->store->get($operationId);
        if ($record === null || $record->operation !== OutboxOperation::Create
            || $record->status !== OutboxStatus::Ambiguous || $remoteId < 1) {
            throw new RuntimeException('Only an ambiguous create may be reconciled to a positive remote ID.');
        }
        $this->mappings->claim($record->connection, $record->localReference, $remoteId);
        $this->store->operatorComplete($operationId, $remoteId, $actor, $reasonCode);
    }

    public function confirmCreateAbsentAndRetry(string $operationId, string $actor, string $reasonCode): void
    {
        $this->validateAudit($actor, $reasonCode);
        $record = $this->store->get($operationId);
        if ($record === null || $record->operation !== OutboxOperation::Create) {
            throw new RuntimeException('Only an ambiguous create may be confirmed absent.');
        }
        $this->store->operatorRetry($operationId, $actor, $reasonCode, true);
    }

    public function retryFailed(string $operationId, string $actor, string $reasonCode): void
    {
        $this->validateAudit($actor, $reasonCode);
        $this->store->operatorRetry($operationId, $actor, $reasonCode, false);
    }

    public function confirmAmbiguousUpdateApplied(
        string $operationId,
        string $actor,
        string $reasonCode,
        bool $previousRequestCannotMutate,
    ): void {
        $record = $this->ambiguousUpdate($operationId, $actor, $reasonCode, $previousRequestCannotMutate);
        $mapping = $this->mappings->find($record->connection, $record->localReference);
        if ($mapping === null || $mapping->remoteState !== RemoteContactState::Active) {
            throw new RuntimeException('An ambiguous update requires its active contact mapping.');
        }
        if ($record->remoteId !== null && $record->remoteId !== $mapping->remoteId) {
            throw new RuntimeException('The ambiguous update evidence does not match its active contact mapping.');
        }
        $this->store->operatorCompleteUpdate($operationId, $mapping->remoteId, $actor, $reasonCode);
    }

    public function authorizeAmbiguousUpdateReplay(
        string $operationId,
        string $actor,
        string $reasonCode,
        bool $previousRequestCannotMutate,
    ): void {
        $record = $this->ambiguousUpdate($operationId, $actor, $reasonCode, $previousRequestCannotMutate);
        $mapping = $this->mappings->find($record->connection, $record->localReference);
        if ($mapping === null || $mapping->remoteState !== RemoteContactState::Active) {
            throw new RuntimeException('An ambiguous update requires its active contact mapping.');
        }
        if ($record->remoteId !== null && $record->remoteId !== $mapping->remoteId) {
            throw new RuntimeException('The ambiguous update evidence does not match its active contact mapping.');
        }
        $this->store->operatorReplayUpdate($operationId, $mapping->remoteId, $actor, $reasonCode);
    }

    public function resumeAuthentication(string $connection, string $actor, string $reasonCode): void
    {
        $this->validateAudit($actor, $reasonCode);
        if (trim($connection) === '' || strlen($connection) > 191) {
            throw new RuntimeException('A valid Mautic connection name is required.');
        }
        $this->store->resumeConnection($connection, $actor, $reasonCode);
    }

    private function validateAudit(string $actor, string $reasonCode): void
    {
        if (trim($actor) === '' || strlen($actor) > 64 || trim($reasonCode) === '' || strlen($reasonCode) > 64) {
            throw new RuntimeException('Operator actor and reason code are required bounded audit values.');
        }
    }

    private function ambiguousUpdate(
        string $operationId,
        string $actor,
        string $reasonCode,
        bool $previousRequestCannotMutate,
    ): OutboxRecord {
        $this->validateAudit($actor, $reasonCode);
        if (! $previousRequestCannotMutate) {
            throw new RuntimeException('Recovery requires trusted attestation that the previous request cannot mutate the contact.');
        }
        $record = $this->store->get($operationId);
        if ($record === null || $record->operation !== OutboxOperation::Update
            || $record->status !== OutboxStatus::Ambiguous) {
            throw new RuntimeException('Only an ambiguous update may use update recovery.');
        }

        return $record;
    }
}
