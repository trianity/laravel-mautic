<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Identity;

enum ContactDiscoveryOutcome: string
{
    case None = 'none';
    case Unique = 'unique';
    case Ambiguous = 'ambiguous';
}
