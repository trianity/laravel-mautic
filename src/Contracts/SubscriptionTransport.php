<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Contracts;

use Trianity\LaravelMautic\Data\SegmentMemberships;

interface SubscriptionTransport
{
    public function segments(string $connection): StaticSegmentGateway;

    public function memberships(string $connection, int $contactId): SegmentMemberships;

    public function addMembership(string $connection, int $contactId, int $segmentId): void;
}
