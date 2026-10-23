<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Contracts;

use Trianity\LaravelMautic\Segment\StaticSegment;
use Trianity\LaravelMautic\Segment\StaticSegmentDefinition;

interface StaticSegmentGateway
{
    public function find(int $remoteId): StaticSegment;

    /** @return list<StaticSegment> */
    public function candidates(string $alias, int $limit = 10): array;

    public function create(StaticSegmentDefinition $definition): StaticSegment;
}
