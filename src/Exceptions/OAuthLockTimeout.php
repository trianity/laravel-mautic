<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Exceptions;

use Trianity\LaravelMautic\OAuth\OAuthAuthenticationFailureKind;

final class OAuthLockTimeout extends OAuthAuthenticationException
{
    public function __construct(string $connection)
    {
        parent::__construct(
            OAuthAuthenticationFailureKind::RefreshLockTimeout,
            $connection,
            'Timed out waiting for the OAuth refresh lock.',
        );
    }
}
