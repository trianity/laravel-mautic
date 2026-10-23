<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Subscription;

use Trianity\LaravelMautic\Identity\LocalContactReference;

final readonly class SubscriptionRecord
{
    /** @param array<string, mixed> $contactPayload */
    public function __construct(
        public string $operationId,
        public string $contactOperationId,
        public string $connection,
        public LocalContactReference $localReference,
        public string $eventKey,
        public string $semanticHash,
        public int $contactRevision,
        public array $contactPayload,
        public SegmentTarget $segment,
        public SubscriptionStatus $status,
        public SubscriptionStage $stage,
        public int $attempts,
        public int $membershipReadAttempts,
        public int $membershipWriteAttempts,
        public ?int $remoteContactId,
        public ?int $remoteSegmentId,
        public ?string $leaseOwner,
        public int $leaseGeneration,
        public ?int $leaseExpiresAt,
        public ?int $nextAttemptAt,
        public ?string $lastFailureKind,
        public ?string $lastErrorCode,
    ) {}
}
