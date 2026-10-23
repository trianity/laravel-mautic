<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Support;

use Trianity\LaravelMautic\Contracts\StaticSegmentGateway;
use Trianity\LaravelMautic\Contracts\SubscriptionTransport;
use Trianity\LaravelMautic\Data\SegmentMemberships;
use Trianity\LaravelMautic\MauticManager;

final readonly class ManagerSubscriptionTransport implements SubscriptionTransport
{
    public function __construct(private MauticManager $manager) {}

    public function segments(string $connection): StaticSegmentGateway
    {
        return $this->manager->connection($connection)->staticSegments();
    }

    public function memberships(string $connection, int $contactId): SegmentMemberships
    {
        return $this->manager->connection($connection)->contactReads()->segments($contactId);
    }

    public function addMembership(string $connection, int $contactId, int $segmentId): void
    {
        $this->manager->connection($connection)->contactWrites()->addToSegment($contactId, $segmentId);
    }
}
