<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Exceptions;

use RuntimeException;

final class OAuthReauthorizationRequired extends RuntimeException
{
    public function __construct(public readonly string $connection)
    {
        parent::__construct('OAuth authorization must be completed interactively for this connection.');
    }
}
