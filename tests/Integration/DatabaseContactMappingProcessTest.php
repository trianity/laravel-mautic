<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;

it('allows only one crossed mapping claim across real processes sharing SQLite', function () {
    $databasePath = tempnam(sys_get_temp_dir(), 'mautic-mapping-');
    expect($databasePath)->toBeString();

    $capsule = new Manager;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => $databasePath, 'prefix' => '']);
    $capsule->setAsGlobal();
    $capsule->schema()->create('mautic_contact_mappings', function (Blueprint $table): void {
        $table->id();
        $table->string('connection', 191);
        $table->string('local_scope', 191);
        $table->string('local_key', 191);
        $table->unsignedBigInteger('remote_id');
        $table->string('remote_state', 16);
        $table->unsignedBigInteger('merged_into_remote_id')->nullable();
        $table->unsignedBigInteger('generation');
        $table->timestamps();
        $table->unique(['connection', 'local_scope', 'local_key']);
        $table->unique(['connection', 'remote_id']);
    });
    $capsule->schema()->create('mapping_ready', function (Blueprint $table): void {
        $table->string('worker')->primary();
    });
    $capsule->schema()->create('mapping_results', function (Blueprint $table): void {
        $table->string('worker')->primary();
        $table->string('result');
    });

    $processes = [];
    $helper = dirname(__DIR__).'/Support/contact_mapping_claim_worker.php';
    foreach ([['one', '42'], ['two', '84']] as [$worker, $remoteId]) {
        $process = proc_open([PHP_BINARY, $helper, $databasePath, $worker, $remoteId], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        expect($process)->toBeResource();
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }

    foreach ($processes as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $stdout."\n".$stderr);
    }

    expect($capsule->table('mapping_results')->where('result', 'claimed')->count())->toBe(1)
        ->and($capsule->table('mapping_results')->where('result', 'conflict')->count())->toBe(1)
        ->and($capsule->table('mautic_contact_mappings')->count())->toBe(1);

    unlink($databasePath);
});
