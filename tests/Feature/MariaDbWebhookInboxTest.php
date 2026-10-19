<?php

declare(strict_types=1);

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Inbox\DatabaseWebhookInboxStore;
use Trianity\LaravelMautic\Inbox\ParsedWebhookEvent;

it('runs the final inbox schema and shared contact lease on disposable MariaDB', function () {
    $host = getenv('MAUTIC_TEST_MARIADB_HOST');
    $database = getenv('MAUTIC_TEST_MARIADB_DATABASE');
    if (! is_string($host) || $host === '' || ! is_string($database) || $database === '') {
        $this->markTestSkipped('Disposable MariaDB is not configured.');
    }
    if (! str_contains(strtolower($database), 'test')) {
        $this->fail('MAUTIC_TEST_MARIADB_DATABASE must name a disposable test database.');
    }
    config()->set('database.connections.inbox-mariadb', [
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
    config()->set('mautic.inbox.database_connection', 'inbox-mariadb');
    DB::purge('inbox-mariadb');
    $schema = Schema::connection('inbox-mariadb');
    $tables = ['mautic_webhook_locks', 'mautic_webhook_events', 'mautic_webhook_deliveries'];
    foreach ($tables as $table) {
        $schema->dropIfExists($table);
    }

    try {
        $this->artisan('vendor:publish', ['--tag' => 'mautic-inbox-migrations', '--force' => true])
            ->assertSuccessful();
        (require database_path('migrations/2026_10_19_030000_create_mautic_webhook_inbox.php'))->up();

        $clock = new class implements Clock
        {
            public function now(): int
            {
                return 1_800_000_000;
            }
        };
        $store = new DatabaseWebhookInboxStore(
            DB::getFacadeRoot(),
            app(Encrypter::class),
            $clock,
            'inbox-mariadb',
        );
        $store->record('mariadb-receipt', 'Main', '{}', [
            new ParsedWebhookEvent(0, 'mautic.lead_post_save_update', ['eventId' => 'Event-A'], 'Event-A', 100, 1_800_000_000),
            new ParsedWebhookEvent(1, 'mautic.lead_post_save_update', ['eventId' => 'event-a'], 'event-a', 100, 1_800_000_000),
        ]);
        $hashes = DB::connection('inbox-mariadb')->table('mautic_webhook_events')
            ->orderBy('ordinal')
            ->pluck('semantic_hash')
            ->all();

        expect($schema->hasTable('mautic_webhook_deliveries'))->toBeTrue()
            ->and($schema->hasTable('mautic_webhook_events'))->toBeTrue()
            ->and($schema->hasTable('mautic_webhook_locks'))->toBeTrue()
            ->and($hashes)->toHaveCount(2)
            ->and($hashes[0])->not->toBe($hashes[1])
            ->and($store->acquireContact('Main', 100, 'worker-one', 30))->toBeTrue()
            ->and($store->acquireContact('Main', 100, 'worker-two', 30))->toBeFalse();
        $store->releaseContact('Main', 100, 'worker-one');
        expect($store->acquireContact('Main', 100, 'worker-two', 30))->toBeTrue();
    } finally {
        foreach ($tables as $table) {
            $schema->dropIfExists($table);
        }
    }
});
