<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Outbox;

use Trianity\LaravelMautic\Identity\LocalContactReference;

final readonly class OutboxRecord
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $operationId,
        public string $connection,
        public LocalContactReference $localReference,
        public string $aggregateHash,
        public int $revision,
        public OutboxOperation $operation,
        public string $source,
        public array $payload,
        public OutboxStatus $status,
        public int $attempts,
        public ?int $nextAttemptAt,
        public ?string $leaseOwner,
        public ?int $leaseExpiresAt,
        public ?string $lastFailureKind,
        public ?int $lastHttpStatus,
        public ?string $lastErrorCode,
        public ?int $remoteId,
        public ?int $dispatchedAt,
        public ?int $completedAt,
    ) {}
}
