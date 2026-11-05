<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Subscription;

final readonly class MembershipPlanItem
{
    public function __construct(
        public int $position,
        public MembershipRequirement $requirement,
        public string $status,
        public ?int $remoteSegmentId,
        public int $readAttempts,
        public int $writeAttempts,
        public ?string $lastErrorCode,
    ) {}
}
