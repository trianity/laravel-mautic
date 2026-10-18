<?php

declare(strict_types=1);

use Mautic\Api\Contacts;
use Trianity\LaravelMautic\Exceptions\InvalidConfiguration;
use Trianity\LaravelMautic\Facades\Mautic;
use Trianity\LaravelMautic\MauticManager;

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
