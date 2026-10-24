<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Reconciliation;

enum OAuthCredentialHealth: string
{
    case NotApplicable = 'not_applicable';
    case Available = 'available';
    case Unavailable = 'unavailable';
}
