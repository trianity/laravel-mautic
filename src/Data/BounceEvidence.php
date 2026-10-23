<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Data;

enum BounceEvidence: string
{
    case NotBounced = 'not_bounced';
    case SubtypeUnavailable = 'subtype_unavailable';
    case Unavailable = 'unavailable';
}
