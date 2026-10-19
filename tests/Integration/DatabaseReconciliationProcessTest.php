<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;

/** @return array{Manager, string} */
function sharedReconciliationProcessDatabase(): array
{
    $databasePath = tempnam(sys_get_temp_dir(), 'mautic-reconciliation-');
    expect($databasePath)->toBeString();
    $capsule = new Manager;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => $databasePath, 'prefix' => '']);
    $capsule->setAsGlobal();
    $capsule->getConnection()->statement('PRAGMA busy_timeout = 5000');
    $capsule->schema()->create('mautic_reconciliation_checkpoints', function (Blueprint $table): void {
        $table->char('connection_hash', 64)->primary();
        $table->string('connection', 191);
        $table->string('scan_id', 64)->unique();
        $table->unsignedBigInteger('high_water_remote_id')->nullable();
        $table->unsignedBigInteger('cursor_remote_id')->default(0);
        $table->string('status', 24);
        $table->unsignedSmallInteger('page_size');
        $table->unsignedBigInteger('observed_count')->default(0);
        $table->unsignedBigInteger('finding_count')->default(0);
        $table->unsignedInteger('request_count')->default(0);
        $table->unsignedBigInteger('next_page_at')->nullable();
        $table->string('last_failure_kind', 64)->nullable();
        $table->unsignedBigInteger('generation')->default(0);
        $table->string('reservation_owner', 64)->nullable();
        $table->unsignedBigInteger('reservation_expires_at')->nullable();
        $table->unsignedBigInteger('started_at');
        $table->unsignedBigInteger('completed_at')->nullable();
        $table->timestamps();
    });
    $capsule->schema()->create('mautic_reconciliation_observations', function (Blueprint $table): void {
        $table->id();
        $table->string('scan_id', 64);
        $table->char('connection_hash', 64);
        $table->unsignedBigInteger('remote_id');
        $table->unsignedBigInteger('observed_at');
        $table->unique(['scan_id', 'remote_id']);
    });
    $capsule->schema()->create('mautic_reconciliation_findings', function (Blueprint $table): void {
        $table->id();
        $table->string('scan_id', 64);
        $table->char('connection_hash', 64);
        $table->unsignedBigInteger('remote_id');
        $table->string('kind', 32);
        $table->unsignedBigInteger('detected_at');
        $table->unique(['scan_id', 'remote_id', 'kind']);
    });
    $capsule->schema()->create('reconciliation_ready', function (Blueprint $table): void {
        $table->string('worker')->primary();
    });
    $capsule->schema()->create('reconciliation_remote_calls', function (Blueprint $table): void {
        $table->id();
        $table->string('worker');
    });
    $capsule->schema()->create('reconciliation_results', function (Blueprint $table): void {
        $table->string('worker')->primary();
        $table->string('result');
    });
    $capsule->schema()->create('reconciliation_control', function (Blueprint $table): void {
        $table->string('name')->primary();
        $table->unsignedBigInteger('value');
    });
    $capsule->table('reconciliation_control')->insert(['name' => 'now', 'value' => 1_000]);

    return [$capsule, $databasePath];
}

/** @return array{resource, array<int, resource>} */
function startReconciliationWorker(string $databasePath, string $mode, string $worker): array
{
    $process = proc_open([PHP_BINARY, dirname(__DIR__).'/Support/reconciliation_process_worker.php', $databasePath, $mode, $worker], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    expect($process)->toBeResource();
    fclose($pipes[0]);

    return [$process, $pipes];
}

/** @param array{resource, array<int, resource>} $worker */
function finishReconciliationWorker(array $worker): void
{
    [$process, $pipes] = $worker;
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0, $stdout."\n".$stderr);
}

function waitForReconciliationWorker(Manager $capsule, string $worker): void
{
    $deadline = microtime(true) + 5;
    while (! $capsule->table('reconciliation_ready')->where('worker', $worker)->exists()
        && microtime(true) < $deadline) {
        usleep(10_000);
    }
    expect($capsule->table('reconciliation_ready')->where('worker', $worker)->exists())->toBeTrue();
}

it('atomically permits only one eligible reconciliation request across processes', function () {
    [$capsule, $databasePath] = sharedReconciliationProcessDatabase();
    $workers = [
        startReconciliationWorker($databasePath, 'race', 'one'),
        startReconciliationWorker($databasePath, 'race', 'two'),
    ];
    foreach ($workers as $worker) {
        finishReconciliationWorker($worker);
    }

    expect($capsule->table('reconciliation_remote_calls')->count())->toBe(1)
        ->and($capsule->table('mautic_reconciliation_checkpoints')->value('request_count'))->toBe(1)
        ->and($capsule->table('mautic_reconciliation_checkpoints')->value('status'))->toBe('completed');
    unlink($databasePath);
});

it('recovers an abandoned reservation and rejects both delayed old success and failure', function () {
    [$capsule, $databasePath] = sharedReconciliationProcessDatabase();
    $old = startReconciliationWorker($databasePath, 'old', 'old');
    $deadline = microtime(true) + 5;
    while (! $capsule->table('reconciliation_ready')->where('worker', 'old-reserved')->exists()
        && microtime(true) < $deadline) {
        usleep(10_000);
    }
    expect($capsule->table('reconciliation_ready')->where('worker', 'old-reserved')->exists())->toBeTrue();

    $capsule->table('reconciliation_control')->where('name', 'now')->update(['value' => 1_010]);
    $recovery = startReconciliationWorker($databasePath, 'recovery', 'recovery');
    finishReconciliationWorker($recovery);
    $capsule->table('reconciliation_ready')->insert(['worker' => 'release-old']);
    finishReconciliationWorker($old);

    expect($capsule->table('reconciliation_remote_calls')->count())->toBe(1)
        ->and($capsule->table('mautic_reconciliation_checkpoints')->value('request_count'))->toBe(2)
        ->and($capsule->table('mautic_reconciliation_checkpoints')->value('status'))->toBe('completed')
        ->and($capsule->table('mautic_reconciliation_checkpoints')->value('last_failure_kind'))->toBeNull()
        ->and($capsule->table('reconciliation_results')->where('worker', 'old')->value('result'))->toBe('success-rejected,failure-rejected');
    unlink($databasePath);
});

it('never substitutes a replacement reservation during update-to-readback handoff', function (string $oldResult) {
    [$capsule, $databasePath] = sharedReconciliationProcessDatabase();
    $old = startReconciliationWorker($databasePath, 'handoff-'.$oldResult, 'old');
    waitForReconciliationWorker($capsule, 'old-reservation-updated');

    $capsule->table('reconciliation_control')->where('name', 'now')->update(['value' => 1_010]);
    $replacement = startReconciliationWorker($databasePath, 'replacement', 'replacement');
    waitForReconciliationWorker($capsule, 'replacement-reserved');
    $owned = $capsule->table('mautic_reconciliation_checkpoints')->first();

    $capsule->table('reconciliation_ready')->insert(['worker' => 'release-old']);
    finishReconciliationWorker($old);

    $current = $capsule->table('mautic_reconciliation_checkpoints')->first();
    expect($capsule->table('reconciliation_remote_calls')->where('worker', 'old')->count())->toBe(0)
        ->and($current->scan_id)->toBe($owned->scan_id)
        ->and($current->generation)->toBe($owned->generation)
        ->and($current->reservation_owner)->toBe($owned->reservation_owner)
        ->and($current->reservation_expires_at)->toBe($owned->reservation_expires_at)
        ->and($current->status)->toBe('running');

    $capsule->table('reconciliation_ready')->insert(['worker' => 'release-replacement']);
    finishReconciliationWorker($replacement);
    expect($capsule->table('reconciliation_results')->where('worker', 'replacement')->value('result'))->toBe('completed')
        ->and($capsule->table('mautic_reconciliation_checkpoints')->value('status'))->toBe('completed');
    unlink($databasePath);
})->with(['success', 'failure']);

it('rejects an old handoff when restart replaces its scan and reservation', function () {
    [$capsule, $databasePath] = sharedReconciliationProcessDatabase();
    $old = startReconciliationWorker($databasePath, 'handoff-success', 'old');
    waitForReconciliationWorker($capsule, 'old-reservation-updated');

    $capsule->table('reconciliation_control')->where('name', 'now')->update(['value' => 1_010]);
    $replacement = startReconciliationWorker($databasePath, 'restart-replacement', 'replacement');
    waitForReconciliationWorker($capsule, 'replacement-reserved');
    $owned = $capsule->table('mautic_reconciliation_checkpoints')->first();

    $capsule->table('reconciliation_ready')->insert(['worker' => 'release-old']);
    finishReconciliationWorker($old);

    $current = $capsule->table('mautic_reconciliation_checkpoints')->first();
    expect($capsule->table('reconciliation_remote_calls')->where('worker', 'old')->count())->toBe(0)
        ->and($current->scan_id)->toBe($owned->scan_id)
        ->and($current->generation)->toBe($owned->generation)
        ->and($current->reservation_owner)->toBe($owned->reservation_owner)
        ->and($current->status)->toBe('running');

    $capsule->table('reconciliation_ready')->insert(['worker' => 'release-replacement']);
    finishReconciliationWorker($replacement);
    expect($capsule->table('reconciliation_results')->where('worker', 'replacement')->value('result'))->toBe('completed')
        ->and($capsule->table('mautic_reconciliation_checkpoints')->value('status'))->toBe('completed');
    unlink($databasePath);
});
