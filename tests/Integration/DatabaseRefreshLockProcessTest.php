<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Trianity\LaravelMautic\Data\OAuthToken;
use Trianity\LaravelMautic\OAuth\DatabaseOAuthStateRepository;
use Trianity\LaravelMautic\OAuth\DatabaseOAuthTokenRepository;
use Trianity\LaravelMautic\OAuth\SystemClock;

it('allows only one refresh through a database shared by real processes', function () {
    $databasePath = tempnam(sys_get_temp_dir(), 'mautic-oauth-lock-');
    expect($databasePath)->toBeString();

    $capsule = new Manager;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => $databasePath, 'prefix' => '']);
    $capsule->setAsGlobal();
    $capsule->schema()->create('mautic_oauth_locks', function (Blueprint $table): void {
        $table->string('name')->primary();
        $table->string('owner', 64);
        $table->unsignedBigInteger('expires_at');
    });
    $capsule->schema()->create('mautic_oauth_tokens', function (Blueprint $table): void {
        $table->string('connection')->primary();
        $table->text('token_payload');
        $table->unsignedBigInteger('expires_at');
        $table->unsignedBigInteger('generation');
        $table->timestamps();
    });
    $capsule->schema()->create('refresh_calls', function (Blueprint $table): void {
        $table->id();
        $table->string('connection');
    });
    $capsule->schema()->create('refresh_ready', function (Blueprint $table): void {
        $table->string('worker')->primary();
    });
    $repository = new DatabaseOAuthTokenRepository(
        $capsule->getDatabaseManager(),
        new Encrypter(str_repeat('k', 32), 'AES-256-CBC'),
    );
    expect($repository->persist('main', new OAuthToken('expired', 'initial-refresh', 1), null))->toBeTrue();

    $processes = [];
    $helper = dirname(__DIR__).'/Support/oauth_lock_worker.php';
    foreach (range(1, 3) as $worker) {
        $process = proc_open([PHP_BINARY, $helper, $databasePath, (string) $worker], [
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

    expect($capsule->table('refresh_calls')->count())->toBe(1)
        ->and($repository->get('main')?->accessToken)->toBe('fresh-access')
        ->and($repository->get('main')?->refreshToken)->toBe('rotated-refresh');

    unlink($databasePath);
});

it('atomically consumes context-bound state once across real processes', function () {
    $databasePath = tempnam(sys_get_temp_dir(), 'mautic-oauth-state-');
    expect($databasePath)->toBeString();

    $capsule = new Manager;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => $databasePath, 'prefix' => '']);
    $capsule->setAsGlobal();
    $capsule->schema()->create('mautic_oauth_states', function (Blueprint $table): void {
        $table->string('state_hash', 64)->primary();
        $table->string('connection');
        $table->string('context_hash', 64);
        $table->unsignedBigInteger('expires_at');
        $table->timestamp('created_at');
    });
    $capsule->schema()->create('consume_ready', function (Blueprint $table): void {
        $table->string('worker')->primary();
    });
    $capsule->schema()->create('consume_results', function (Blueprint $table): void {
        $table->string('worker')->primary();
        $table->boolean('consumed');
    });
    $states = new DatabaseOAuthStateRepository($capsule->getDatabaseManager(), new SystemClock);
    $state = $states->issue('main', 'trusted-context', 300);

    $processes = [];
    $helper = dirname(__DIR__).'/Support/oauth_state_consume_worker.php';
    foreach (['one', 'two'] as $worker) {
        $process = proc_open([PHP_BINARY, $helper, $databasePath, $state, $worker], [
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

    expect($capsule->table('consume_results')->where('consumed', true)->count())->toBe(1)
        ->and($capsule->table('consume_results')->where('consumed', false)->count())->toBe(1);

    unlink($databasePath);
});
