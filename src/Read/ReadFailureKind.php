<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Read;

enum ReadFailureKind: string
{
    case NotFound = 'not_found';
    case Authentication = 'authentication';
    case Validation = 'validation';
    case Timeout = 'timeout';
    case Throttled = 'throttled';
    case MalformedResponse = 'malformed_response';
    case UnexpectedResponse = 'unexpected_response';
    case RemoteServer = 'remote_server';
    case Transport = 'transport';
}
