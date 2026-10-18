<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Write;

enum FailureKind: string
{
    case Authentication = 'authentication';
    case Validation = 'validation';
    case Timeout = 'timeout';
    case Throttled = 'throttled';
    case MalformedResponse = 'malformed_response';
    case UnexpectedResponse = 'unexpected_response';
    case RemoteServer = 'remote_server';
    case Transport = 'transport';
    case AmbiguousOutcome = 'ambiguous_outcome';
    case DuplicateContact = 'duplicate_contact';
    case TargetNotFound = 'target_not_found';
}
