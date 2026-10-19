<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Exceptions;

use Trianity\LaravelMautic\OAuth\OAuthAuthenticationFailureKind;

final class OAuthReauthorizationRequired extends OAuthAuthenticationException
{
    public function __construct(string $connection, public readonly ?int $status = null)
    {
        parent::__construct(
            OAuthAuthenticationFailureKind::AuthorizationRequired,
            $connection,
            'OAuth authorization must be completed interactively for this connection.',
        );
    }
}
