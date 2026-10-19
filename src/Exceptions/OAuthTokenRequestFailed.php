<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Exceptions;

use Trianity\LaravelMautic\OAuth\OAuthAuthenticationFailureKind;
use Trianity\LaravelMautic\OAuth\OAuthTokenFailureKind;

final class OAuthTokenRequestFailed extends OAuthAuthenticationException
{
    public function __construct(
        string $connection,
        public readonly OAuthTokenFailureKind $kind,
        public readonly ?int $status = null,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct(
            OAuthAuthenticationFailureKind::TokenEndpoint,
            $connection,
            'The OAuth token endpoint request failed.',
        );
    }
}
