<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ServiceProvider;
use Trianity\LaravelMautic\Contracts\AuthenticationFactory;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Contracts\ConnectionFactory;
use Trianity\LaravelMautic\Contracts\HttpClientFactory;
use Trianity\LaravelMautic\Contracts\OAuthClientCredentialsProvider;
use Trianity\LaravelMautic\Contracts\OAuthRefreshLock;
use Trianity\LaravelMautic\Contracts\OAuthStateRepository;
use Trianity\LaravelMautic\Contracts\OAuthTokenEndpoint;
use Trianity\LaravelMautic\Contracts\OAuthTokenRepository;
use Trianity\LaravelMautic\Http\GuzzleHttpClientFactory;
use Trianity\LaravelMautic\OAuth\DatabaseOAuthStateRepository;
use Trianity\LaravelMautic\OAuth\DatabaseOAuthTokenRepository;
use Trianity\LaravelMautic\OAuth\DatabaseRefreshLock;
use Trianity\LaravelMautic\OAuth\EnvironmentOAuthClientCredentialsProvider;
use Trianity\LaravelMautic\OAuth\HttpOAuthTokenEndpoint;
use Trianity\LaravelMautic\OAuth\OAuth2Service;
use Trianity\LaravelMautic\OAuth\OAuthTokenManager;
use Trianity\LaravelMautic\OAuth\SystemClock;
use Trianity\LaravelMautic\Support\ApiLibraryAuthenticationFactory;
use Trianity\LaravelMautic\Support\ApiLibraryConnectionFactory;

final class MauticServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mautic.php', 'mautic');

        $this->app->bindIf(HttpClientFactory::class, GuzzleHttpClientFactory::class);
        $this->app->bindIf(Clock::class, SystemClock::class);
        $this->app->bindIf(OAuthClientCredentialsProvider::class, EnvironmentOAuthClientCredentialsProvider::class);
        $this->app->bindIf(OAuthTokenEndpoint::class, HttpOAuthTokenEndpoint::class);
        $this->app->bindIf(AuthenticationFactory::class, ApiLibraryAuthenticationFactory::class);
        $this->app->singletonIf(OAuthTokenRepository::class, function ($app): OAuthTokenRepository {
            return new DatabaseOAuthTokenRepository(
                $app['db'],
                $app['encrypter'],
                $this->databaseConnection(),
                $this->storageTable('tokens_table', 'mautic_oauth_tokens'),
            );
        });
        $this->app->singletonIf(OAuthStateRepository::class, function ($app): OAuthStateRepository {
            return new DatabaseOAuthStateRepository(
                $app['db'],
                $app->make(Clock::class),
                $this->databaseConnection(),
                $this->storageTable('states_table', 'mautic_oauth_states'),
            );
        });
        $this->app->singletonIf(OAuthRefreshLock::class, function ($app): OAuthRefreshLock {
            return new DatabaseRefreshLock(
                $app['db'],
                $app->make(Clock::class),
                $this->databaseConnection(),
                (int) $app['config']->get('mautic.oauth.poll_milliseconds', 100),
                $this->storageTable('locks_table', 'mautic_oauth_locks'),
            );
        });
        $this->app->singleton(OAuthTokenManager::class, function ($app): OAuthTokenManager {
            return new OAuthTokenManager(
                $app->make(OAuthTokenRepository::class),
                $app->make(OAuthTokenEndpoint::class),
                $app->make(OAuthRefreshLock::class),
                $app->make(Clock::class),
                (int) $app['config']->get('mautic.oauth.expiry_leeway_seconds', 60),
                (int) $app['config']->get('mautic.oauth.lock_wait_seconds', 5),
                (int) $app['config']->get('mautic.oauth.lock_lease_seconds', 30),
            );
        });
        $this->app->singleton(OAuth2Service::class, function ($app): OAuth2Service {
            return new OAuth2Service(
                $app['config'],
                $app->make(OAuthStateRepository::class),
                $app->make(OAuthTokenRepository::class),
                $app->make(OAuthTokenEndpoint::class),
                (int) $app['config']->get('mautic.oauth.state_ttl_seconds', 600),
            );
        });
        $this->app->bindIf(ConnectionFactory::class, ApiLibraryConnectionFactory::class);
        $this->app->singleton(MauticManager::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/mautic.php' => config_path('mautic.php'),
            ], 'mautic-config');
            $this->publishes([
                __DIR__.'/../database/migrations/create_mautic_oauth_storage.php.stub' => database_path('migrations/2026_10_19_000000_create_mautic_oauth_storage.php'),
            ], 'mautic-migrations');
        }
    }

    private function databaseConnection(): ?string
    {
        $name = $this->app->make(Repository::class)->get('mautic.oauth.database_connection');

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function storageTable(string $key, string $default): string
    {
        $table = $this->app->make(Repository::class)->get('mautic.oauth.'.$key, $default);

        return is_string($table) && $table !== '' ? $table : $default;
    }
}
