<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Reconciliation;

enum OAuthTokenHealth: string
{
    case NotApplicable = 'not_applicable';
    case StorageUnavailable = 'storage_unavailable';
    case Missing = 'missing';
    case Valid = 'valid';
    case Expiring = 'expiring';
    case Expired = 'expired';
}
