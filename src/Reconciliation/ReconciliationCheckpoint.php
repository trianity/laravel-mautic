<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Reconciliation;

final readonly class ReconciliationCheckpoint
{
    public function __construct(
        public string $scanId,
        public string $connection,
        public ?int $highWaterRemoteId,
        public int $cursorRemoteId,
        public ReconciliationStatus $status,
        public int $pageSize,
        public int $observedCount,
        public int $findingCount,
        public int $requestCount,
        public ?int $nextPageAt,
        public ?string $lastFailureKind,
        public int $generation,
        public ?string $reservationOwner,
        public ?int $reservationExpiresAt,
    ) {}
}
