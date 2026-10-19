<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Reconciliation;

enum ReconciliationStatus: string
{
    case Running = 'running';
    case Failed = 'failed';
    case Completed = 'completed';
}
