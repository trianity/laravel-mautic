<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Console;

use Illuminate\Console\Command;
use Trianity\LaravelMautic\Reconciliation\DatabaseMauticDiagnostics;

final class MauticDiagnosticsCommand extends Command
{
    protected $signature = 'mautic:diagnose {connection : Named Mautic connection}';

    protected $description = 'Report connection-scoped package health without contact payloads or secrets';

    public function handle(DatabaseMauticDiagnostics $diagnostics): int
    {
        $this->line(json_encode(
            $diagnostics->forConnection((string) $this->argument('connection'))->toArray(),
            JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
