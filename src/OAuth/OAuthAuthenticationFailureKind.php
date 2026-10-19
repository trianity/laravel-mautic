<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\OAuth;

enum OAuthAuthenticationFailureKind: string
{
    case AuthorizationRequired = 'authorization_required';
    case RefreshLockTimeout = 'refresh_lock_timeout';
    case TokenEndpoint = 'token_endpoint';
    case TokenPersistenceUncertain = 'token_persistence_uncertain';
    case PreRequest = 'pre_request';
}
