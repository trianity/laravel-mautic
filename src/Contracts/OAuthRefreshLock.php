<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Contracts;

use Closure;

interface OAuthRefreshLock
{
    public function block(string $connection, int $waitSeconds, int $leaseSeconds, Closure $callback): mixed;
}
