<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Subscription;

enum SubscriptionStage: string
{
    case Recorded = 'recorded';
    case ContactReady = 'contact_ready';
    case SegmentProvisioningInFlight = 'segment_provisioning_in_flight';
    case SegmentReady = 'segment_ready';
    case MembershipInFlight = 'membership_in_flight';
    case Completed = 'completed';
}
