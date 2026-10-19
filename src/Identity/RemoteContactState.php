<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Identity;

enum RemoteContactState: string
{
    case Active = 'active';
    case Missing = 'missing';
    case Deleted = 'deleted';
    case Merged = 'merged';
}
