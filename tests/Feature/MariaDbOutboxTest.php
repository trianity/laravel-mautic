<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Identity\LocalContactReference;
use Trianity\LaravelMautic\Outbox\ContactSyncCommand;
use Trianity\LaravelMautic\Outbox\DatabaseOutboxStore;
use Trianity\LaravelMautic\Write\ContactFields;

it('runs the final outbox schema and shared lease contract on disposable MariaDB', function () {
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
    $tables = [
        'mautic_outbox_connection_blocks',
        'mautic_outbox_audits',
        'mautic_outbox_locks',
        'mautic_outbox_commands',
    ];
    foreach ($tables as $table) {
        $schema->dropIfExists($table);
    }

    try {
        $collation = DB::connection('outbox-mariadb')->selectOne('select @@collation_database as collation');
        expect(strtolower((string) ($collation->collation ?? '')))->toEndWith('_ci');
        $this->artisan('vendor:publish', ['--tag' => 'mautic-outbox-migrations', '--force' => true])
            ->assertSuccessful();
        (require database_path('migrations/2026_10_19_020000_create_mautic_contact_outbox.php'))->up();

        expect($schema->hasTable('mautic_outbox_commands'))->toBeTrue()
            ->and($schema->hasTable('mautic_outbox_locks'))->toBeTrue()
            ->and($schema->hasTable('mautic_outbox_audits'))->toBeTrue()
            ->and($schema->hasTable('mautic_outbox_connection_blocks'))->toBeTrue()
            ->and($schema->hasColumn('mautic_outbox_commands', 'legacy_recovery'))->toBeFalse()
            ->and($schema->hasTable('mautic_outbox_migration_state'))->toBeFalse();

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
        foreach ($tables as $table) {
            $schema->dropIfExists($table);
        }
    }
});
