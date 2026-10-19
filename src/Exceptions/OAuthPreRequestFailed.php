<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Exceptions;

use Trianity\LaravelMautic\OAuth\OAuthAuthenticationFailureKind;

final class OAuthPreRequestFailed extends OAuthAuthenticationException
{
    public function __construct(string $connection)
    {
        parent::__construct(
            OAuthAuthenticationFailureKind::PreRequest,
            $connection,
            'OAuth authentication failed before the API request was sent.',
        );
    }
}
