<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\OAuth;

use Trianity\LaravelMautic\Contracts\Clock;

final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }
}
