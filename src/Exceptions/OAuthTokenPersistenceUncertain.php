<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Exceptions;

use Trianity\LaravelMautic\OAuth\OAuthAuthenticationFailureKind;

final class OAuthTokenPersistenceUncertain extends OAuthAuthenticationException
{
    public function __construct(string $connection)
    {
        parent::__construct(
            OAuthAuthenticationFailureKind::TokenPersistenceUncertain,
            $connection,
            'The remote OAuth rotation succeeded but local token persistence is uncertain.',
        );
    }
}
