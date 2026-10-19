<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Reconciliation;

enum ReconciliationFindingKind: string
{
    case UnmappedRemote = 'unmapped_remote';
    case MappingNotActive = 'mapping_not_active';
    case RemoteMissing = 'remote_missing';
}
