<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Exceptions;

use RuntimeException;
use Trianity\LaravelMautic\OAuth\OAuthAuthenticationFailureKind;

abstract class OAuthAuthenticationException extends RuntimeException
{
    public function __construct(
        public readonly OAuthAuthenticationFailureKind $failureKind,
        public readonly string $connection,
        string $message,
    ) {
        parent::__construct($message);
    }
}
