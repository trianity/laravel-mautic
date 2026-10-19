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

it('runs the published outbox migration and shared lease contract on disposable MariaDB', function () {
    $host = getenv('MAUTIC_TEST_MARIADB_HOST');
    $database = getenv('MAUTIC_TEST_MARIADB_DATABASE');
    if (! is_string($host) || $host === '' || ! is_string($database) || $database === '') {
        $this->markTestSkipped('Disposable MariaDB is not configured.');
    }
    if (! str_contains(strtolower($database), 'test')) {
        $this->fail('MAUTIC_TEST_MARIADB_DATABASE must name a disposable test database.');
    }
    config()->set('database.connections.outbox-mariadb', [
        'driver' => 'mysql',
        'host' => $host,
        'port' => (int) (getenv('MAUTIC_TEST_MARIADB_PORT') ?: 3306),
        'database' => $database,
        'username' => getenv('MAUTIC_TEST_MARIADB_USERNAME') ?: 'root',
        'password' => getenv('MAUTIC_TEST_MARIADB_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
    ]);
    config()->set('mautic.outbox.database_connection', 'outbox-mariadb');
    DB::purge('outbox-mariadb');
    $schema = Schema::connection('outbox-mariadb');
    foreach (['mautic_outbox_connection_blocks', 'mautic_outbox_audits', 'mautic_outbox_locks', 'mautic_outbox_commands'] as $table) {
        $schema->dropIfExists($table);
    }

    try {
        $collation = DB::connection('outbox-mariadb')->selectOne('select @@collation_database as collation');
        expect(strtolower((string) ($collation->collation ?? '')))->toEndWith('_ci');
        $this->artisan('vendor:publish', ['--tag' => 'mautic-outbox-migrations', '--force' => true])
            ->assertSuccessful();
        (require database_path('migrations/2026_10_19_020000_create_mautic_contact_outbox.php'))->up();
        (require database_path('migrations/2026_10_19_021000_correct_mautic_contact_outbox.php'))->up();
        $clock = new class implements Clock
        {
            public function now(): int
            {
                return 1_000;
            }
        };
        $store = new DatabaseOutboxStore(DB::getFacadeRoot(), $clock, 'outbox-mariadb');
        $first = ContactSyncCommand::update(
            'mariadb-case-one',
            'Main',
            new LocalContactReference('tenant:Café', 'Contact '),
            1,
            ContactFields::fromArray(['firstname' => 'Ada'], ['firstname']),
        );
        $second = ContactSyncCommand::update(
            'mariadb-case-two',
            'main',
            new LocalContactReference('tenant:cafe', 'contact'),
            1,
            ContactFields::fromArray(['firstname' => 'Grace'], ['firstname']),
        );
        $store->record($first);
        $store->record($second);

        expect(DB::connection('outbox-mariadb')->table('mautic_outbox_commands')->count())->toBe(2)
            ->and($store->acquire($first->aggregateHash(), 'worker-one', 30))->toBeTrue()
            ->and($store->acquire($first->aggregateHash(), 'worker-two', 30))->toBeFalse();
        $store->release($first->aggregateHash(), 'worker-one');
        expect($store->acquire($first->aggregateHash(), 'worker-two', 30))->toBeTrue();
    } finally {
        foreach (['mautic_outbox_connection_blocks', 'mautic_outbox_audits', 'mautic_outbox_locks', 'mautic_outbox_commands'] as $table) {
            $schema->dropIfExists($table);
        }
    }
});

it('upgrades the earlier outbox schema on disposable MariaDB', function () {
    $host = getenv('MAUTIC_TEST_MARIADB_HOST');
    $database = getenv('MAUTIC_TEST_MARIADB_DATABASE');
    if (! is_string($host) || $host === '' || ! is_string($database) || $database === '') {
        $this->markTestSkipped('Disposable MariaDB is not configured.');
    }
    if (! str_contains(strtolower($database), 'test')) {
        $this->fail('MAUTIC_TEST_MARIADB_DATABASE must name a disposable test database.');
    }
    config()->set('database.connections.outbox-mariadb', [
        'driver' => 'mysql',
        'host' => $host,
        'port' => (int) (getenv('MAUTIC_TEST_MARIADB_PORT') ?: 3306),
        'database' => $database,
        'username' => getenv('MAUTIC_TEST_MARIADB_USERNAME') ?: 'root',
        'password' => getenv('MAUTIC_TEST_MARIADB_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
    ]);
    config()->set('mautic.outbox.database_connection', 'outbox-mariadb');
    DB::purge('outbox-mariadb');
    $schema = Schema::connection('outbox-mariadb');
    foreach (['mautic_outbox_connection_blocks', 'mautic_outbox_audits', 'mautic_outbox_locks', 'mautic_outbox_commands'] as $table) {
        $schema->dropIfExists($table);
    }

    try {
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
        DB::connection('outbox-mariadb')->table('mautic_outbox_commands')->insert([
            'operation_id' => 'legacy-mariadb',
            'connection' => 'Main',
            'local_scope' => 'tenant:acme',
            'local_key' => 'contact-1',
            'aggregate_hash' => str_repeat('a', 64),
            'revision' => 1,
            'operation' => 'update',
            'source' => 'host',
            'payload' => '{}',
            'status' => 'superseded',
            'attempts' => 0,
        ]);
        $this->artisan('vendor:publish', ['--tag' => 'mautic-outbox-migrations', '--force' => true])
            ->assertSuccessful();
        (require database_path('migrations/2026_10_19_021000_correct_mautic_contact_outbox.php'))->up();
        $row = DB::connection('outbox-mariadb')->table('mautic_outbox_commands')->first();

        expect($row?->connection_hash)->toBe(ContactIdentityHasher::connection('Main'))
            ->and($row?->delivery_stage)->toBe('recorded')
            ->and($row?->status)->toBe('pending')
            ->and($schema->hasTable('mautic_outbox_connection_blocks'))->toBeTrue();
    } finally {
        foreach (['mautic_outbox_connection_blocks', 'mautic_outbox_audits', 'mautic_outbox_locks', 'mautic_outbox_commands'] as $table) {
            $schema->dropIfExists($table);
        }
    }
});
