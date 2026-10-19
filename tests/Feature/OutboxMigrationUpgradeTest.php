<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Identity\ContactIdentityHasher;
use Trianity\LaravelMautic\Identity\LocalContactReference;
use Trianity\LaravelMautic\Outbox\ContactSyncCommand;
use Trianity\LaravelMautic\Outbox\DatabaseOutboxStore;
use Trianity\LaravelMautic\Write\ContactFields;

beforeEach(function () {
    config()->set('database.connections.outbox-upgrade', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    config()->set('mautic.outbox.database_connection', 'outbox-upgrade');
    DB::purge('outbox-upgrade');
});

it('upgrades an existing outbox with connection identity and conservative delivery stages', function () {
    $schema = Schema::connection('outbox-upgrade');
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
    });
    DB::connection('outbox-upgrade')->table('mautic_outbox_commands')->insert([
        'operation_id' => 'legacy-processing',
        'connection' => 'Main',
        'local_scope' => 'tenant:acme',
        'local_key' => 'contact-1',
        'aggregate_hash' => str_repeat('a', 64),
        'revision' => 1,
        'operation' => 'update',
        'source' => 'host',
        'payload' => '{}',
        'status' => 'processing',
        'attempts' => 1,
    ]);
    DB::connection('outbox-upgrade')->table('mautic_outbox_commands')->insert([
        'operation_id' => 'legacy-superseded',
        'connection' => 'Main',
        'local_scope' => 'tenant:acme',
        'local_key' => 'contact-1',
        'aggregate_hash' => str_repeat('a', 64),
        'revision' => 2,
        'operation' => 'update',
        'source' => 'host',
        'payload' => '{}',
        'status' => 'superseded',
        'attempts' => 0,
    ]);

    $this->artisan('vendor:publish', ['--tag' => 'mautic-outbox-migrations', '--force' => true])
        ->assertSuccessful();
    (require database_path('migrations/2026_10_19_021000_correct_mautic_contact_outbox.php'))->up();
    (require database_path('migrations/2026_10_19_022000_reconcile_legacy_mautic_contact_outbox.php'))->up();
    $row = DB::connection('outbox-upgrade')->table('mautic_outbox_commands')->where('operation_id', 'legacy-processing')->first();

    expect($row?->connection_hash)->toBe(ContactIdentityHasher::connection('Main'))
        ->and($row?->delivery_stage)->toBe('operation_in_flight')
        ->and(DB::connection('outbox-upgrade')->table('mautic_outbox_commands')->where('operation_id', 'legacy-superseded')->value('status'))->toBe('ambiguous')
        ->and(DB::connection('outbox-upgrade')->table('mautic_outbox_commands')->where('operation_id', 'legacy-superseded')->value('last_error_code'))->toBe('legacy_superseded_reconciliation_required')
        ->and($schema->hasTable('mautic_outbox_connection_blocks'))->toBeTrue();
});

it('repairs an installation that already ran the unsafe 05A migration without replaying history', function () {
    $this->artisan('vendor:publish', ['--tag' => 'mautic-outbox-migrations', '--force' => true])
        ->assertSuccessful();
    (require database_path('migrations/2026_10_19_020000_create_mautic_contact_outbox.php'))->up();
    Schema::connection('outbox-upgrade')->table('mautic_outbox_commands', function (Blueprint $table): void {
        $table->dropColumn('legacy_recovery');
    });
    $database = DB::connection('outbox-upgrade');
    $base = [
        'connection' => 'Main',
        'connection_hash' => ContactIdentityHasher::connection('Main'),
        'local_scope' => 'tenant:acme',
        'operation' => 'update',
        'source' => 'host',
        'attempts' => 0,
        'delivery_stage' => 'recorded',
        'created_at' => '2026-10-19 10:00:00',
        'updated_at' => '2026-10-19 10:00:00',
    ];
    foreach ([
        ['same-v1', 'same', str_repeat('a', 64), 1, '{"firstname":"Older"}', 'pending'],
        ['same-v2', 'same', str_repeat('a', 64), 2, '{"firstname":"Newer"}', 'completed'],
        ['partial-v1', 'partial', str_repeat('b', 64), 1, '{"firstname":"Retained"}', 'pending'],
        ['partial-v2', 'partial', str_repeat('b', 64), 2, '{"phone":"+361"}', 'completed'],
    ] as [$operationId, $localKey, $aggregateHash, $revision, $payload, $status]) {
        $database->table('mautic_outbox_commands')->insert($base + [
            'operation_id' => $operationId,
            'local_key' => $localKey,
            'aggregate_hash' => $aggregateHash,
            'revision' => $revision,
            'payload' => $payload,
            'status' => $status,
        ]);
    }

    (require database_path('migrations/2026_10_19_022000_reconcile_legacy_mautic_contact_outbox.php'))->up();
    $clock = new class implements Clock
    {
        public function now(): int
        {
            return 1_000;
        }
    };
    $store = new DatabaseOutboxStore(DB::getFacadeRoot(), $clock, 'outbox-upgrade');

    expect($database->table('mautic_outbox_commands')->where('operation_id', 'same-v1')->value('status'))->toBe('ambiguous')
        ->and($database->table('mautic_outbox_commands')->where('operation_id', 'same-v2')->value('status'))->toBe('completed')
        ->and($database->table('mautic_outbox_commands')->where('operation_id', 'partial-v1')->value('payload'))->toBe('{"firstname":"Retained"}')
        ->and($database->table('mautic_outbox_commands')->where('operation_id', 'partial-v1')->value('status'))->toBe('ambiguous')
        ->and($database->table('mautic_outbox_commands')->whereIn('operation_id', ['same-v1', 'partial-v1'])->where('last_error_code', 'legacy_superseded_reconciliation_required')->count())->toBe(2)
        ->and($store->recoverableOperationIds(10))->toBe([]);
});

it('keeps fresh installations recoverable after all outbox migrations', function () {
    $this->artisan('vendor:publish', ['--tag' => 'mautic-outbox-migrations', '--force' => true])
        ->assertSuccessful();
    (require database_path('migrations/2026_10_19_020000_create_mautic_contact_outbox.php'))->up();
    (require database_path('migrations/2026_10_19_021000_correct_mautic_contact_outbox.php'))->up();
    (require database_path('migrations/2026_10_19_022000_reconcile_legacy_mautic_contact_outbox.php'))->up();
    $clock = new class implements Clock
    {
        public function now(): int
        {
            return 1_000;
        }
    };
    $store = new DatabaseOutboxStore(DB::getFacadeRoot(), $clock, 'outbox-upgrade');
    $store->record(ContactSyncCommand::update(
        'fresh-command',
        'Main',
        new LocalContactReference('tenant:acme', 'fresh'),
        1,
        ContactFields::fromArray(['firstname' => 'Fresh'], ['firstname']),
    ));

    expect($store->recoverableOperationIds(1))->toBe(['fresh-command']);
});
