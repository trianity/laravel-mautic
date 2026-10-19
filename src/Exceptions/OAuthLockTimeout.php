<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Exceptions;

use RuntimeException;

final class OAuthLockTimeout extends RuntimeException
{
    public function __construct(public readonly string $connection)
    {
        parent::__construct('Timed out waiting for the OAuth refresh lock.');
    }
}
