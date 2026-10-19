<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Reconciliation\DatabaseReconciliationStore;
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
    DB::purge('reconciliation-mariadb');
    $schema = Schema::connection('reconciliation-mariadb');
    $tables = [
        'mautic_reconciliation_audits',
        'mautic_reconciliation_findings',
        'mautic_reconciliation_observations',
        'mautic_reconciliation_checkpoints',
    ];
    foreach ($tables as $table) {
        $schema->dropIfExists($table);
    }

    try {
        $this->artisan('vendor:publish', ['--tag' => 'mautic-reconciliation-migrations', '--force' => true])
            ->assertSuccessful();
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
        $checkpoint = $store->setHighWatermark($store->begin('Main', 50), 20, 0);
        $checkpoint = $store->recordPage(
            $checkpoint,
            [10, 20, 20],
            [20 => ReconciliationFindingKind::UnmappedRemote],
            20,
            true,
            1,
        );

        expect($schema->hasTable('mautic_reconciliation_checkpoints'))->toBeTrue()
            ->and($schema->hasTable('mautic_reconciliation_observations'))->toBeTrue()
            ->and($schema->hasTable('mautic_reconciliation_findings'))->toBeTrue()
            ->and($schema->hasTable('mautic_reconciliation_audits'))->toBeTrue()
            ->and($checkpoint->status)->toBe(ReconciliationStatus::Completed)
            ->and($checkpoint->observedCount)->toBe(2)
            ->and($checkpoint->findingCount)->toBe(1)
            ->and($store->observationCount($checkpoint->scanId))->toBe(2)
            ->and($store->get('main'))->toBeNull();
    } finally {
        foreach ($tables as $table) {
            $schema->dropIfExists($table);
        }
    }
});
