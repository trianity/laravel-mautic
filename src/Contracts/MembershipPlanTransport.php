<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Contracts;

interface MembershipPlanTransport extends SubscriptionTransport
{
    public function removeMembership(string $connection, int $contactId, int $segmentId): void;
}
