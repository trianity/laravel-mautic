<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Subscription;

final readonly class SubscriptionClaim
{
    public function __construct(
        public SubscriptionRecord $record,
        public string $owner,
        public int $generation,
        public int $expiresAt,
    ) {}
}
