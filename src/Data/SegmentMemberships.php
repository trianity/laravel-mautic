<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Data;

final readonly class SegmentMemberships
{
    /** @param list<SegmentMembership> $memberships */
    public function __construct(
        public int $contactId,
        public array $memberships,
    ) {}
}
