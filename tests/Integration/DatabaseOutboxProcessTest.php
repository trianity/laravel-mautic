<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Identity\DatabaseContactMappingRepository;
use Trianity\LaravelMautic\Identity\LocalContactReference;
use Trianity\LaravelMautic\OAuth\SystemClock;
use Trianity\LaravelMautic\Outbox\ContactSyncCommand;
use Trianity\LaravelMautic\Outbox\DatabaseOutboxStore;
use Trianity\LaravelMautic\Write\ContactFields;

/** @return array{Manager, string} */
function sharedOutboxProcessDatabase(): array
{
    $databasePath = tempnam(sys_get_temp_dir(), 'mautic-outbox-');
    expect($databasePath)->toBeString();
    $capsule = new Manager;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => $databasePath, 'prefix' => '']);
    $capsule->setAsGlobal();
    $capsule->getConnection()->statement('PRAGMA busy_timeout = 5000');
    $schema = $capsule->schema();
    $schema->create('mautic_contact_mappings', function (Blueprint $table): void {
        $table->id();
        $table->string('connection', 191);
        $table->char('connection_hash', 64);
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
        $table->char('connection_hash', 64);
        $table->string('local_scope', 191);
        $table->string('local_key', 191);
        $table->char('aggregate_hash', 64);
        $table->unsignedBigInteger('revision');
        $table->string('operation', 16);
        $table->string('source', 64);
        $table->text('payload');
        $table->string('status', 24);
        $table->unsignedInteger('attempts')->default(0);
        $table->string('delivery_stage', 32)->default('recorded');
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
    $schema->create('mautic_outbox_connection_blocks', function (Blueprint $table): void {
        $table->char('connection_hash', 64)->primary();
        $table->string('connection', 191);
        $table->string('reason_code', 64);
        $table->unsignedBigInteger('created_at');
    });
    $schema->create('outbox_ready', function (Blueprint $table): void {
        $table->string('worker')->primary();
    });
    $schema->create('outbox_remote_calls', function (Blueprint $table): void {
        $table->id();
        $table->string('worker');
        $table->string('operation_id')->nullable();
    });
    $schema->create('outbox_results', function (Blueprint $table): void {
        $table->string('worker')->primary();
        $table->string('result');
    });
    $schema->create('outbox_control', function (Blueprint $table): void {
        $table->string('name')->primary();
        $table->unsignedBigInteger('value');
    });
    $capsule->table('outbox_control')->insert(['name' => 'now', 'value' => 1_000]);

    return [$capsule, $databasePath];
}

it('serializes duplicate outbox jobs across real processes sharing SQLite', function () {
    [$capsule, $databasePath] = sharedOutboxProcessDatabase();
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

it('blocks a newer revision when a shared-database lease expires during an in-flight mutation', function () {
    [$capsule, $databasePath] = sharedOutboxProcessDatabase();
    $reference = new LocalContactReference('tenant:acme', 'lease-expiry');
    (new DatabaseContactMappingRepository($capsule->getDatabaseManager()))->claim('main', $reference, 42);
    $store = new DatabaseOutboxStore($capsule->getDatabaseManager(), new class($capsule) implements Clock
    {
        public function __construct(private Manager $capsule) {}

        public function now(): int
        {
            return (int) $this->capsule->table('outbox_control')->where('name', 'now')->value('value');
        }
    });
    $store->record(ContactSyncCommand::update(
        'lease-expiry-v1',
        'main',
        $reference,
        1,
        ContactFields::fromArray(['firstname' => 'Older'], ['firstname']),
    ));
    $store->record(ContactSyncCommand::update(
        'lease-expiry-v2',
        'main',
        $reference,
        2,
        ContactFields::fromArray(['firstname' => 'Newer'], ['firstname']),
    ));

    $helper = dirname(__DIR__).'/Support/outbox_expiry_worker.php';
    $old = proc_open([PHP_BINARY, $helper, $databasePath, 'old'], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $oldPipes);
    expect($old)->toBeResource();
    fclose($oldPipes[0]);
    $deadline = microtime(true) + 5;
    while (! $capsule->table('outbox_ready')->where('worker', 'old-in-flight')->exists() && microtime(true) < $deadline) {
        usleep(10_000);
    }
    expect($capsule->table('outbox_ready')->where('worker', 'old-in-flight')->exists())->toBeTrue();
    $capsule->table('outbox_control')->where('name', 'now')->update(['value' => 1_002]);

    $recovery = proc_open([PHP_BINARY, $helper, $databasePath, 'recovery'], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $recoveryPipes);
    expect($recovery)->toBeResource();
    fclose($recoveryPipes[0]);
    $recoveryOut = stream_get_contents($recoveryPipes[1]);
    $recoveryErr = stream_get_contents($recoveryPipes[2]);
    fclose($recoveryPipes[1]);
    fclose($recoveryPipes[2]);
    expect(proc_close($recovery))->toBe(0, $recoveryOut."\n".$recoveryErr);

    $capsule->table('outbox_ready')->insert(['worker' => 'release-old']);
    $oldOut = stream_get_contents($oldPipes[1]);
    $oldErr = stream_get_contents($oldPipes[2]);
    fclose($oldPipes[1]);
    fclose($oldPipes[2]);
    expect(proc_close($old))->toBe(0, $oldOut."\n".$oldErr)
        ->and($capsule->table('outbox_remote_calls')->count())->toBe(1)
        ->and($capsule->table('mautic_outbox_commands')->where('operation_id', 'lease-expiry-v1')->value('status'))->toBe('ambiguous')
        ->and($capsule->table('mautic_outbox_commands')->where('operation_id', 'lease-expiry-v2')->value('status'))->toBe('pending');
    unlink($databasePath);
});
