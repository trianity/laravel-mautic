<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Trianity\LaravelMautic\Identity\DatabaseContactMappingRepository;
use Trianity\LaravelMautic\Identity\LocalContactReference;

it('enforces byte-exact identity on a disposable case-insensitive MariaDB database', function () {
    $host = getenv('MAUTIC_TEST_MARIADB_HOST');
    $database = getenv('MAUTIC_TEST_MARIADB_DATABASE');
    if (! is_string($host) || $host === '' || ! is_string($database) || $database === '') {
        $this->markTestSkipped('Disposable MariaDB is not configured.');
    }
    if (! str_contains(strtolower($database), 'test')) {
        $this->fail('MAUTIC_TEST_MARIADB_DATABASE must name a disposable test database.');
    }

    config()->set('database.connections.identity-mariadb', [
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
    config()->set('mautic.identity.database_connection', 'identity-mariadb');
    DB::purge('identity-mariadb');
    $collation = DB::connection('identity-mariadb')->selectOne('select @@collation_database as collation');
    expect(strtolower((string) ($collation->collation ?? '')))->toEndWith('_ci');

    Schema::connection('identity-mariadb')->dropIfExists('mautic_contact_mappings');
    try {
        $this->artisan('vendor:publish', ['--tag' => 'mautic-identity-migrations', '--force' => true])
            ->assertSuccessful();
        $migration = require database_path('migrations/2026_10_19_010000_create_mautic_contact_mappings.php');
        $migration->up();
        $repository = new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'identity-mariadb');

        $repository->claim('main', new LocalContactReference('tenant:Café', 'Contact'), 41);
        $repository->claim('main', new LocalContactReference('tenant:cafe', 'contact'), 42);
        $repository->claim('main', new LocalContactReference('tenant:cafe', 'contact '), 43);

        expect(DB::connection('identity-mariadb')->table('mautic_contact_mappings')->count())->toBe(3)
            ->and($repository->find('main', new LocalContactReference('tenant:cafe', 'contact '))?->remoteId)
            ->toBe(43);
    } finally {
        Schema::connection('identity-mariadb')->dropIfExists('mautic_contact_mappings');
    }
});
