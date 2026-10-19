<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Contracts\ContactReader;
use Trianity\LaravelMautic\Contracts\ContactReaderProvider;
use Trianity\LaravelMautic\Fakes\FakeContactReader;
use Trianity\LaravelMautic\Identity\DatabaseContactMappingRepository;
use Trianity\LaravelMautic\Identity\LocalContactReference;
use Trianity\LaravelMautic\Identity\RemoteContactState;
use Trianity\LaravelMautic\Reconciliation\DatabaseMappingInventory;
use Trianity\LaravelMautic\Reconciliation\DatabaseMappingRecovery;
use Trianity\LaravelMautic\Reconciliation\DatabaseReconciliationStore;
use Trianity\LaravelMautic\Reconciliation\MappingRecoveryService;
use Trianity\LaravelMautic\Reconciliation\ReconciliationFindingKind;
use Trianity\LaravelMautic\Reconciliation\ReconciliationStatus;

it('runs the final reconciliation checkpoint and deduplication schema on disposable MariaDB', function () {
    $host = getenv('MAUTIC_TEST_MARIADB_HOST');
    $database = getenv('MAUTIC_TEST_MARIADB_DATABASE');
    if (! is_string($host) || $host === '' || ! is_string($database) || $database === '') {
        $this->markTestSkipped('Disposable MariaDB is not configured.');
    }
    if (! str_contains(strtolower($database), 'test')) {
        $this->fail('MAUTIC_TEST_MARIADB_DATABASE must name a disposable test database.');
    }
    config()->set('database.connections.reconciliation-mariadb', [
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
    config()->set('mautic.reconciliation.database_connection', 'reconciliation-mariadb');
    config()->set('mautic.identity.database_connection', 'reconciliation-mariadb');
    DB::purge('reconciliation-mariadb');
    $schema = Schema::connection('reconciliation-mariadb');
    $tables = [
        'mautic_reconciliation_audits',
        'mautic_reconciliation_findings',
        'mautic_reconciliation_observations',
        'mautic_reconciliation_checkpoints',
        'mautic_contact_mappings',
    ];
    foreach ($tables as $table) {
        $schema->dropIfExists($table);
    }

    try {
        $this->artisan('vendor:publish', ['--tag' => 'mautic-reconciliation-migrations', '--force' => true])
            ->assertSuccessful();
        $this->artisan('vendor:publish', ['--tag' => 'mautic-identity-migrations', '--force' => true])
            ->assertSuccessful();
        (require database_path('migrations/2026_10_19_010000_create_mautic_contact_mappings.php'))->up();
        (require database_path('migrations/2026_10_19_040000_create_mautic_reconciliation.php'))->up();
        $clock = new class implements Clock
        {
            public function now(): int
            {
                return 1_800_000_000;
            }
        };
        $store = new DatabaseReconciliationStore(
            DB::getFacadeRoot(),
            $clock,
            'reconciliation-mariadb',
        );
        $checkpoint = $store->reserve($store->begin('Main', 50), 0, 30)
            ?? throw new RuntimeException('The watermark request was not reserved.');
        $checkpoint = $store->setHighWatermark($checkpoint, 20);
        $checkpoint = $store->reserve($checkpoint, 0, 30)
            ?? throw new RuntimeException('The page request was not reserved.');
        $checkpoint = $store->recordPage(
            $checkpoint,
            [10, 20, 20],
            [20 => ReconciliationFindingKind::UnmappedRemote],
            20,
            true,
        );
        $mappings = new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'reconciliation-mariadb');
        $mappings->claim('main', new LocalContactReference('tenant:test', 'lower'), 20);
        $upper = $mappings->claim('Main', new LocalContactReference('tenant:test', 'upper-20'), 20);
        $mappings->claim('Main', new LocalContactReference('tenant:test', 'upper-30'), 30);
        $reader = FakeContactReader::make();
        $readers = new class($reader) implements ContactReaderProvider
        {
            public function __construct(private ContactReader $reader) {}

            public function forConnection(string $connection): ContactReader
            {
                return $this->reader;
            }
        };
        $recovery = new MappingRecoveryService(
            new DatabaseMappingInventory(DB::getFacadeRoot(), $mappings, 'reconciliation-mariadb'),
            $readers,
            new DatabaseMappingRecovery(DB::getFacadeRoot(), $clock, 'reconciliation-mariadb'),
        );
        $dryRun = $recovery->run('Main', limit: 1);
        $applied = $recovery->run('Main', limit: 1, applyMissing: true, actor: 'operator', reasonCode: 'confirmed_404');
        $next = $recovery->run('Main', afterRemoteId: 20, limit: 1);

        expect($schema->hasTable('mautic_reconciliation_checkpoints'))->toBeTrue()
            ->and($schema->hasTable('mautic_reconciliation_observations'))->toBeTrue()
            ->and($schema->hasTable('mautic_reconciliation_findings'))->toBeTrue()
            ->and($schema->hasTable('mautic_reconciliation_audits'))->toBeTrue()
            ->and($checkpoint->status)->toBe(ReconciliationStatus::Completed)
            ->and($checkpoint->observedCount)->toBe(2)
            ->and($checkpoint->findingCount)->toBe(1)
            ->and($store->observationCount($checkpoint->scanId))->toBe(2)
            ->and($store->get('main'))->toBeNull()
            ->and($dryRun->missingRemoteIds)->toBe([20])
            ->and($dryRun->complete)->toBeFalse()
            ->and($applied->applied)->toBe(1)
            ->and($mappings->find('Main', $upper->localReference)?->remoteState)->toBe(RemoteContactState::Missing)
            ->and($mappings->findByRemoteId('main', 20)?->remoteState)->toBe(RemoteContactState::Active)
            ->and($next->missingRemoteIds)->toBe([30])
            ->and(DB::connection('reconciliation-mariadb')->table('mautic_reconciliation_audits')->count())->toBe(1);
    } finally {
        foreach ($tables as $table) {
            $schema->dropIfExists($table);
        }
    }
});
