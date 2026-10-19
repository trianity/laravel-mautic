<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Fields;

enum SharedFieldResolution: string
{
    case HostWins = 'host_wins';
    case MauticWins = 'mautic_wins';
}
