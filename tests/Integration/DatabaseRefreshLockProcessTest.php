<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Trianity\LaravelMautic\Data\OAuthToken;
use Trianity\LaravelMautic\OAuth\DatabaseOAuthTokenRepository;

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
        $table->timestamps();
    });
    $capsule->schema()->create('refresh_calls', function (Blueprint $table): void {
        $table->id();
        $table->string('connection');
    });
    $repository = new DatabaseOAuthTokenRepository(
        $capsule->getDatabaseManager(),
        new Encrypter(str_repeat('k', 32), 'AES-256-CBC'),
    );
    $repository->save('main', new OAuthToken('expired', 'initial-refresh', 1));

    $processes = [];
    $helper = dirname(__DIR__).'/Support/oauth_lock_worker.php';
    foreach (range(1, 3) as $_worker) {
        $process = proc_open([PHP_BINARY, $helper, $databasePath], [
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
