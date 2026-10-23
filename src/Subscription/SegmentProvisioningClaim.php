<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Subscription;

final readonly class SegmentProvisioningClaim
{
    public function __construct(
        public string $connection,
        public string $segmentKey,
        public string $operationId,
        public string $owner,
        public int $generation,
        public int $expiresAt,
    ) {}
}
