<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;

it('coordinates one segment create attempt across independent processes', function () {
    $databasePath = tempnam(sys_get_temp_dir(), 'mautic-subscription-');
    expect($databasePath)->toBeString();
    $capsule = new Manager;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => $databasePath, 'prefix' => '']);
    $capsule->setAsGlobal();
    $capsule->getConnection()->statement('PRAGMA busy_timeout = 5000');
    $schema = $capsule->schema();
    $schema->create('mautic_subscription_segment_locks', function (Blueprint $table): void {
        $table->char('segment_key_hash', 64)->primary();
        $table->string('owner', 64);
        $table->unsignedBigInteger('expires_at');
    });
    $schema->create('mautic_subscription_segments', function (Blueprint $table): void {
        $table->id();
        $table->string('connection', 191);
        $table->char('connection_hash', 64);
        $table->string('segment_key', 191);
        $table->char('segment_key_hash', 64)->unique();
        $table->unsignedBigInteger('remote_id');
        $table->char('remote_identity_hash', 64)->unique();
        $table->char('definition_hash', 64);
        $table->timestamps();
    });
    $schema->create('subscription_ready', function (Blueprint $table): void {
        $table->string('worker')->primary();
    });
    $schema->create('subscription_create_attempts', function (Blueprint $table): void {
        $table->id();
        $table->string('worker');
    });
    $schema->create('subscription_results', function (Blueprint $table): void {
        $table->string('worker')->primary();
        $table->boolean('acquired');
    });

    $processes = [];
    $helper = dirname(__DIR__).'/Support/subscription_segment_worker.php';
    foreach (['one', 'two'] as $worker) {
        $process = proc_open([PHP_BINARY, $helper, $databasePath, $worker], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
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

    expect($capsule->table('subscription_create_attempts')->count())->toBe(1)
        ->and($capsule->table('subscription_results')->where('acquired', true)->count())->toBe(1)
        ->and($capsule->table('mautic_subscription_segments')->count())->toBe(1)
        ->and($capsule->table('mautic_subscription_segments')->value('remote_id'))->toBe(91);
    unlink($databasePath);
});
