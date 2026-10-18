<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Data;

final readonly class SegmentMembership
{
    public function __construct(
        public int $remoteId,
        public string $name,
        public ?string $alias,
    ) {}
}
