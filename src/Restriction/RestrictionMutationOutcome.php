<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Restriction;

enum RestrictionMutationOutcome: string
{
    case ManualPresent = 'manual_present';
    case ExistingRestrictionRetained = 'existing_restriction_retained';
    case NoRestrictionPresent = 'no_restriction_present';
    case Released = 'released';
    case RestrictionRemains = 'restriction_remains';
    case NoMatchingRestriction = 'no_matching_restriction';
}
