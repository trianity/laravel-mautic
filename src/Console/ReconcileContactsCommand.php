<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Console;

use Illuminate\Console\Command;
use Trianity\LaravelMautic\Reconciliation\ContactReconciler;
use Trianity\LaravelMautic\Reconciliation\ReconciliationStatus;

final class ReconcileContactsCommand extends Command
{
    protected $signature = 'mautic:reconcile
        {connection : Named Mautic connection}
        {--restart : Start a new bounded scan after discarding only the current checkpoint}';

    protected $description = 'Advance a bounded, durable Mautic contact reconciliation scan';

    public function handle(ContactReconciler $reconciler): int
    {
        $checkpoint = $reconciler->run((string) $this->argument('connection'), (bool) $this->option('restart'));
        $this->line(json_encode([
            'scan_id' => $checkpoint->scanId,
            'status' => $checkpoint->status->value,
            'high_water_remote_id' => $checkpoint->highWaterRemoteId,
            'cursor_remote_id' => $checkpoint->cursorRemoteId,
            'observed_count' => $checkpoint->observedCount,
            'finding_count' => $checkpoint->findingCount,
            'request_count' => $checkpoint->requestCount,
            'next_page_at' => $checkpoint->nextPageAt,
            'last_failure_kind' => $checkpoint->lastFailureKind,
        ], JSON_THROW_ON_ERROR));

        return $checkpoint->status === ReconciliationStatus::Failed ? self::FAILURE : self::SUCCESS;
    }
}
