<?php

declare(strict_types=1);

use Mautic\Api\Contacts;
use Trianity\LaravelMautic\Exceptions\InvalidConfiguration;
use Trianity\LaravelMautic\Facades\Mautic;
use Trianity\LaravelMautic\MauticManager;
use Trianity\LaravelMautic\MauticServiceProvider;

beforeEach(function () {
    config()->set('mautic.connections', [
        'main' => ['url' => 'https://first.example.test', 'username' => 'one', 'password' => 'one-secret'],
        'second' => ['url' => 'https://second.example.test', 'username' => 'two', 'password' => 'two-secret'],
    ]);
});

it('resolves the provider manager and facade without sending requests', function () {
    $manager = app(MauticManager::class);

    expect($manager)->toBe(app(MauticManager::class));
    expect(Mautic::connection())->toBe($manager->connection('main'));
    expect($manager->connection()->contacts())->toBeInstanceOf(Contacts::class);
});

it('keeps named connections separate and caches them until purged', function () {
    $manager = app(MauticManager::class);
    $first = $manager->connection();
    $second = $manager->connection('second');

    expect($second)->not->toBe($first);
    expect($second->name)->toBe('second');
    $manager->purge();
    expect($manager->connection())->not->toBe($first);
    expect($manager->connection('second'))->toBe($second);
    $manager->forgetConnections();
    expect($manager->connection('second'))->not->toBe($second);
});

it('does not silently fall back for unknown named connections', function () {
    expect(fn () => app(MauticManager::class)->connection('missing'))
        ->toThrow(InvalidConfiguration::class);
});

it('validates the default connection name', function () {
    config()->set('mautic.default', null);

    expect(fn () => app(MauticManager::class)->connection())
        ->toThrow(InvalidConfiguration::class);
});

it('publishes configuration', function () {
    $this->artisan('vendor:publish', ['--tag' => 'mautic-config', '--force' => true])->assertSuccessful();

    expect(is_file(config_path('mautic.php')))->toBeTrue();
});

it('publishes canonical migrations through exact subsystem and aggregate tags', function () {
    $migrations = [
        'mautic-oauth-migrations' => [
            'create_mautic_oauth_storage.php.stub',
            '2026_10_19_000000_create_mautic_oauth_storage.php',
        ],
        'mautic-identity-migrations' => [
            'create_mautic_contact_mappings.php.stub',
            '2026_10_19_010000_create_mautic_contact_mappings.php',
        ],
        'mautic-outbox-migrations' => [
            'create_mautic_contact_outbox.php.stub',
            '2026_10_19_020000_create_mautic_contact_outbox.php',
        ],
        'mautic-inbox-migrations' => [
            'create_mautic_webhook_inbox.php.stub',
            '2026_10_19_030000_create_mautic_webhook_inbox.php',
        ],
        'mautic-reconciliation-migrations' => [
            'create_mautic_reconciliation.php.stub',
            '2026_10_19_040000_create_mautic_reconciliation.php',
        ],
        'mautic-subscription-migrations' => [
            'create_mautic_subscription_delivery.php.stub',
            '2026_10_19_050000_create_mautic_subscription_delivery.php',
        ],
    ];

    foreach ($migrations as $tag => [$source, $destination]) {
        $published = MauticServiceProvider::pathsToPublish(MauticServiceProvider::class, $tag);

        expect($published)->toHaveCount(1)
            ->and(basename((string) array_key_first($published)))->toBe($source)
            ->and(basename((string) reset($published)))->toBe($destination);
    }

    $aggregate = MauticServiceProvider::pathsToPublish(MauticServiceProvider::class, 'mautic-migrations');

    expect($aggregate)->toHaveCount(6)
        ->and(array_map('basename', array_keys($aggregate)))
        ->toBe(array_column($migrations, 0))
        ->and(array_map('basename', array_values($aggregate)))
        ->toBe(array_column($migrations, 1))
        ->and(array_unique(array_values($aggregate)))->toHaveCount(6);
});
