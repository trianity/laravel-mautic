<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\ServiceProvider;
use Trianity\LaravelMautic\Contracts\AuthenticationFactory;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Contracts\ConnectionFactory;
use Trianity\LaravelMautic\Contracts\ContactMappingRepository;
use Trianity\LaravelMautic\Contracts\ContactSyncTransport;
use Trianity\LaravelMautic\Contracts\HttpClientFactory;
use Trianity\LaravelMautic\Contracts\OAuthClientCredentialsProvider;
use Trianity\LaravelMautic\Contracts\OAuthRefreshLock;
use Trianity\LaravelMautic\Contracts\OAuthStateRepository;
use Trianity\LaravelMautic\Contracts\OAuthTokenEndpoint;
use Trianity\LaravelMautic\Contracts\OAuthTokenRepository;
use Trianity\LaravelMautic\Exceptions\InvalidConfiguration;
use Trianity\LaravelMautic\Http\GuzzleHttpClientFactory;
use Trianity\LaravelMautic\Identity\DatabaseContactMappingRepository;
use Trianity\LaravelMautic\OAuth\DatabaseOAuthStateRepository;
use Trianity\LaravelMautic\OAuth\DatabaseOAuthTokenRepository;
use Trianity\LaravelMautic\OAuth\DatabaseRefreshLock;
use Trianity\LaravelMautic\OAuth\EnvironmentOAuthClientCredentialsProvider;
use Trianity\LaravelMautic\OAuth\HttpOAuthTokenEndpoint;
use Trianity\LaravelMautic\OAuth\OAuth2Service;
use Trianity\LaravelMautic\OAuth\OAuthTokenManager;
use Trianity\LaravelMautic\OAuth\SystemClock;
use Trianity\LaravelMautic\Outbox\ContactOutbox;
use Trianity\LaravelMautic\Outbox\DatabaseOutboxStore;
use Trianity\LaravelMautic\Outbox\OutboxDispatcher;
use Trianity\LaravelMautic\Outbox\OutboxOperator;
use Trianity\LaravelMautic\Outbox\OutboxPoller;
use Trianity\LaravelMautic\Outbox\OutboxProcessor;
use Trianity\LaravelMautic\Support\ApiLibraryAuthenticationFactory;
use Trianity\LaravelMautic\Support\ApiLibraryConnectionFactory;
use Trianity\LaravelMautic\Support\ManagerContactSyncTransport;

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
        $this->app->bindIf(ContactSyncTransport::class, ManagerContactSyncTransport::class);
        $this->app->singletonIf(ContactMappingRepository::class, function ($app): ContactMappingRepository {
            $connection = $app['config']->get('mautic.identity.database_connection');
            $table = $app['config']->get('mautic.identity.mappings_table', 'mautic_contact_mappings');

            return new DatabaseContactMappingRepository(
                $app['db'],
                is_string($connection) && $connection !== '' ? $connection : null,
                is_string($table) && $table !== '' ? $table : 'mautic_contact_mappings',
            );
        });
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
                $app->make(OAuthRefreshLock::class),
                (int) $app['config']->get('mautic.oauth.state_ttl_seconds', 600),
                (int) $app['config']->get('mautic.oauth.lock_wait_seconds', 5),
                (int) $app['config']->get('mautic.oauth.lock_lease_seconds', 30),
            );
        });
        $this->app->singleton(DatabaseOutboxStore::class, function ($app): DatabaseOutboxStore {
            $connection = $app['config']->get('mautic.outbox.database_connection');

            return new DatabaseOutboxStore(
                $app['db'],
                $app->make(Clock::class),
                is_string($connection) && $connection !== '' ? $connection : null,
                $this->outboxTable('commands_table', 'mautic_outbox_commands'),
                $this->outboxTable('locks_table', 'mautic_outbox_locks'),
                $this->outboxTable('audits_table', 'mautic_outbox_audits'),
                $this->outboxTable('connection_blocks_table', 'mautic_outbox_connection_blocks'),
            );
        });
        $this->app->singleton(OutboxDispatcher::class, function ($app): OutboxDispatcher {
            $queue = $app['config']->get('mautic.outbox.queue');

            return new OutboxDispatcher(
                $app->make(Queue::class),
                $app->make(DatabaseOutboxStore::class),
                is_string($queue) && $queue !== '' ? $queue : null,
            );
        });
        $this->app->singleton(ContactOutbox::class);
        $this->app->singleton(OutboxPoller::class);
        $this->app->singleton(OutboxProcessor::class, function ($app): OutboxProcessor {
            $leaseSeconds = (int) $app['config']->get('mautic.outbox.lease_seconds', 30);
            $operationBudgetSeconds = (int) $app['config']->get('mautic.outbox.operation_budget_seconds', 20);
            $this->validateOutboxLease($app['config'], $leaseSeconds, $operationBudgetSeconds);

            return new OutboxProcessor(
                $app->make(DatabaseOutboxStore::class),
                $app->make(ContactSyncTransport::class),
                $app->make(ContactMappingRepository::class),
                $app->make(Clock::class),
                $leaseSeconds,
                (int) $app['config']->get('mautic.outbox.max_attempts', 5),
                (int) $app['config']->get('mautic.outbox.maximum_delay_seconds', 300),
            );
        });
        $this->app->singleton(OutboxOperator::class);
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
            $identityMigration = [
                __DIR__.'/../database/migrations/create_mautic_contact_mappings.php.stub' => database_path('migrations/2026_10_19_010000_create_mautic_contact_mappings.php'),
                __DIR__.'/../database/migrations/add_identity_hashes_to_mautic_contact_mappings.php.stub' => database_path('migrations/2026_10_19_011000_add_identity_hashes_to_mautic_contact_mappings.php'),
            ];
            $this->publishes($identityMigration, 'mautic-migrations');
            $this->publishes($identityMigration, 'mautic-identity-migrations');
            $outboxMigration = [
                __DIR__.'/../database/migrations/create_mautic_contact_outbox.php.stub' => database_path('migrations/2026_10_19_020000_create_mautic_contact_outbox.php'),
            ];
            $this->publishes($outboxMigration, 'mautic-migrations');
            $this->publishes($outboxMigration, 'mautic-outbox-migrations');
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

    private function outboxTable(string $key, string $default): string
    {
        $table = $this->app->make(Repository::class)->get('mautic.outbox.'.$key, $default);

        return is_string($table) && $table !== '' ? $table : $default;
    }

    private function validateOutboxLease(Repository $config, int $leaseSeconds, int $operationBudgetSeconds): void
    {
        if ($operationBudgetSeconds < 1 || $leaseSeconds <= $operationBudgetSeconds) {
            throw new InvalidConfiguration('The outbox lease must be longer than the declared complete-operation budget.');
        }
        $connections = $config->get('mautic.connections', []);
        if (! is_array($connections)) {
            throw new InvalidConfiguration('Mautic connections must be a named array.');
        }
        foreach ($connections as $values) {
            if (! is_array($values)) {
                continue;
            }
            $timeout = $values['timeout'] ?? 10;
            if ((is_int($timeout) || is_float($timeout)) && $operationBudgetSeconds < $timeout) {
                throw new InvalidConfiguration('The outbox operation budget must cover every individual Mautic request timeout.');
            }
        }
    }
}
