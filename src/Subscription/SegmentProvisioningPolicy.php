<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Subscription;

enum SegmentProvisioningPolicy: string
{
    case ExistingOnly = 'existing_only';
    case CreateIfAbsent = 'create_if_absent';
}
