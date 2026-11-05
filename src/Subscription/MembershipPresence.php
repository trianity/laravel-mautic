<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Subscription;

enum MembershipPresence: string
{
    case Present = 'present';
    case Absent = 'absent';
}
