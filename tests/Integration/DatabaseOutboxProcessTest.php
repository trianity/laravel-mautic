<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Trianity\LaravelMautic\Identity\DatabaseContactMappingRepository;
use Trianity\LaravelMautic\Identity\LocalContactReference;
use Trianity\LaravelMautic\OAuth\SystemClock;
use Trianity\LaravelMautic\Outbox\ContactSyncCommand;
use Trianity\LaravelMautic\Outbox\DatabaseOutboxStore;
use Trianity\LaravelMautic\Write\ContactFields;

it('serializes duplicate outbox jobs across real processes sharing SQLite', function () {
    $databasePath = tempnam(sys_get_temp_dir(), 'mautic-outbox-');
    expect($databasePath)->toBeString();
    $capsule = new Manager;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => $databasePath, 'prefix' => '']);
    $capsule->setAsGlobal();
    $schema = $capsule->schema();
    $schema->create('mautic_contact_mappings', function (Blueprint $table): void {
        $table->id();
        $table->string('connection', 191);
        $table->string('local_scope', 191);
        $table->string('local_key', 191);
        $table->char('local_identity_hash', 64)->unique();
        $table->unsignedBigInteger('remote_id');
        $table->char('remote_identity_hash', 64)->unique();
        $table->string('remote_state', 16);
        $table->unsignedBigInteger('merged_into_remote_id')->nullable();
        $table->unsignedBigInteger('generation');
        $table->timestamps();
    });
    $schema->create('mautic_outbox_commands', function (Blueprint $table): void {
        $table->id();
        $table->string('operation_id', 64)->unique();
        $table->string('connection', 191);
        $table->string('local_scope', 191);
        $table->string('local_key', 191);
        $table->char('aggregate_hash', 64);
        $table->unsignedBigInteger('revision');
        $table->string('operation', 16);
        $table->string('source', 64);
        $table->text('payload');
        $table->string('status', 24);
        $table->unsignedInteger('attempts')->default(0);
        $table->unsignedBigInteger('next_attempt_at')->nullable();
        $table->string('lease_owner', 64)->nullable();
        $table->unsignedBigInteger('lease_expires_at')->nullable();
        $table->string('last_failure_kind', 32)->nullable();
        $table->unsignedSmallInteger('last_http_status')->nullable();
        $table->string('last_error_code', 64)->nullable();
        $table->unsignedBigInteger('remote_id')->nullable();
        $table->unsignedBigInteger('dispatched_at')->nullable();
        $table->unsignedBigInteger('completed_at')->nullable();
        $table->timestamps();
        $table->unique(['aggregate_hash', 'revision']);
    });
    $schema->create('mautic_outbox_locks', function (Blueprint $table): void {
        $table->char('aggregate_hash', 64)->primary();
        $table->string('owner', 64);
        $table->unsignedBigInteger('expires_at');
    });
    $schema->create('outbox_ready', function (Blueprint $table): void {
        $table->string('worker')->primary();
    });
    $schema->create('outbox_remote_calls', function (Blueprint $table): void {
        $table->id();
        $table->string('worker');
    });
    $schema->create('outbox_results', function (Blueprint $table): void {
        $table->string('worker')->primary();
        $table->string('result');
    });
    $reference = new LocalContactReference('tenant:acme', 'contact-01');
    (new DatabaseContactMappingRepository($capsule->getDatabaseManager()))->claim('main', $reference, 42);
    (new DatabaseOutboxStore($capsule->getDatabaseManager(), new SystemClock))->record(
        ContactSyncCommand::update(
            'process-race',
            'main',
            $reference,
            1,
            ContactFields::fromArray(['firstname' => 'Ada'], ['firstname']),
        ),
    );

    $processes = [];
    $helper = dirname(__DIR__).'/Support/outbox_process_worker.php';
    foreach (['one', 'two'] as $worker) {
        $process = proc_open([PHP_BINARY, $helper, $databasePath, $worker], [
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

    expect($capsule->table('outbox_remote_calls')->count())->toBe(1)
        ->and($capsule->table('mautic_outbox_commands')->value('status'))->toBe('completed')
        ->and($capsule->table('outbox_results')->count())->toBe(2);
    unlink($databasePath);
});
