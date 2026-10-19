<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Console;

use Illuminate\Console\Command;
use Trianity\LaravelMautic\Reconciliation\MappingRecoveryService;

final class ReconcileMappingsCommand extends Command
{
    protected $signature = 'mautic:reconcile-mappings
        {connection : Named Mautic connection}
        {--after=0 : Exclusive remote-ID cursor}
        {--limit=50 : Maximum mappings to inspect, from 1 to 100}
        {--apply-missing : Explicitly mark confirmed missing remote contacts}
        {--actor= : Required operator identifier when applying}
        {--reason= : Required bounded reason code when applying}';

    protected $description = 'Dry-run or explicitly apply a bounded stale-mapping recovery batch';

    public function handle(MappingRecoveryService $recovery): int
    {
        $report = $recovery->run(
            (string) $this->argument('connection'),
            (int) $this->option('after'),
            (int) $this->option('limit'),
            (bool) $this->option('apply-missing'),
            is_string($this->option('actor')) ? $this->option('actor') : null,
            is_string($this->option('reason')) ? $this->option('reason') : null,
        );
        $this->line(json_encode([
            'connection' => $report->connection,
            'dry_run' => $report->dryRun,
            'checked' => $report->checked,
            'missing_remote_ids' => $report->missingRemoteIds,
            'applied' => $report->applied,
            'next_remote_id' => $report->nextRemoteId,
            'complete' => $report->complete,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
