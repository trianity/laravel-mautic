<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Exceptions;

use RuntimeException;

final class InvalidOAuthState extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The OAuth callback state is invalid, expired, already used, or belongs to another connection.');
    }
}
