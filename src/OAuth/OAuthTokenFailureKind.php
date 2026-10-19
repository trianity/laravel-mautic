<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\OAuth;

enum OAuthTokenFailureKind: string
{
    case InvalidClient = 'invalid_client';
    case Throttled = 'throttled';
    case RemoteServer = 'remote_server';
    case Network = 'network';
    case MalformedResponse = 'malformed_response';
    case ClientRequest = 'client_request';
}
