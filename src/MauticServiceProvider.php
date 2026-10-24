<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Trianity\LaravelMautic\Console\MauticDiagnosticsCommand;
use Trianity\LaravelMautic\Console\OAuthCallbackUrlCommand;
use Trianity\LaravelMautic\Console\ReconcileContactsCommand;
use Trianity\LaravelMautic\Console\ReconcileMappingsCommand;
use Trianity\LaravelMautic\Contracts\AuthenticationFactory;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Contracts\ConnectionFactory;
use Trianity\LaravelMautic\Contracts\ContactMappingRepository;
use Trianity\LaravelMautic\Contracts\ContactReaderProvider;
use Trianity\LaravelMautic\Contracts\ContactSyncTransport;
use Trianity\LaravelMautic\Contracts\HttpClientFactory;
use Trianity\LaravelMautic\Contracts\InboundContactProjector;
use Trianity\LaravelMautic\Contracts\OAuthBrowserAuthorizer;
use Trianity\LaravelMautic\Contracts\OAuthClientCredentialsProvider;
use Trianity\LaravelMautic\Contracts\OAuthRefreshLock;
use Trianity\LaravelMautic\Contracts\OAuthStateRepository;
use Trianity\LaravelMautic\Contracts\OAuthTokenEndpoint;
use Trianity\LaravelMautic\Contracts\OAuthTokenRepository;
use Trianity\LaravelMautic\Contracts\ReconciliationSource;
use Trianity\LaravelMautic\Contracts\SubscriptionTransport;
use Trianity\LaravelMautic\Contracts\WebhookSecretProvider;
use Trianity\LaravelMautic\Exceptions\InvalidConfiguration;
use Trianity\LaravelMautic\Http\Controllers\OAuthBrowserController;
use Trianity\LaravelMautic\Http\GuzzleHttpClientFactory;
use Trianity\LaravelMautic\Identity\DatabaseContactMappingRepository;
use Trianity\LaravelMautic\Inbox\DatabaseWebhookInboxStore;
use Trianity\LaravelMautic\Inbox\EnvironmentWebhookSecretProvider;
use Trianity\LaravelMautic\Inbox\WebhookContactMapper;
use Trianity\LaravelMautic\Inbox\WebhookInbox;
use Trianity\LaravelMautic\Inbox\WebhookInboxDispatcher;
use Trianity\LaravelMautic\Inbox\WebhookInboxPoller;
use Trianity\LaravelMautic\Inbox\WebhookInboxProcessor;
use Trianity\LaravelMautic\OAuth\DatabaseOAuthStateRepository;
use Trianity\LaravelMautic\OAuth\DatabaseOAuthTokenRepository;
use Trianity\LaravelMautic\OAuth\DatabaseRefreshLock;
use Trianity\LaravelMautic\OAuth\DenyOAuthBrowserAuthorizer;
use Trianity\LaravelMautic\OAuth\EnvironmentOAuthClientCredentialsProvider;
use Trianity\LaravelMautic\OAuth\HttpOAuthTokenEndpoint;
use Trianity\LaravelMautic\OAuth\OAuth2Service;
use Trianity\LaravelMautic\OAuth\OAuthCallbackUrlResolver;
use Trianity\LaravelMautic\OAuth\OAuthTokenManager;
use Trianity\LaravelMautic\OAuth\SystemClock;
use Trianity\LaravelMautic\Outbox\ContactOutbox;
use Trianity\LaravelMautic\Outbox\DatabaseOutboxStore;
use Trianity\LaravelMautic\Outbox\OutboxDispatcher;
use Trianity\LaravelMautic\Outbox\OutboxOperator;
use Trianity\LaravelMautic\Outbox\OutboxPoller;
use Trianity\LaravelMautic\Outbox\OutboxProcessor;
use Trianity\LaravelMautic\Reconciliation\ContactReconciler;
use Trianity\LaravelMautic\Reconciliation\DatabaseMappingInventory;
use Trianity\LaravelMautic\Reconciliation\DatabaseMappingRecovery;
use Trianity\LaravelMautic\Reconciliation\DatabaseMauticDiagnostics;
use Trianity\LaravelMautic\Reconciliation\DatabaseReconciliationStore;
use Trianity\LaravelMautic\Reconciliation\MappingRecoveryService;
use Trianity\LaravelMautic\Subscription\DatabaseSubscriptionStore;
use Trianity\LaravelMautic\Subscription\SubscriptionDelivery;
use Trianity\LaravelMautic\Subscription\SubscriptionDispatcher;
use Trianity\LaravelMautic\Subscription\SubscriptionOperator;
use Trianity\LaravelMautic\Subscription\SubscriptionPoller;
use Trianity\LaravelMautic\Subscription\SubscriptionProcessor;
use Trianity\LaravelMautic\Support\ApiLibraryAuthenticationFactory;
use Trianity\LaravelMautic\Support\ApiLibraryConnectionFactory;
use Trianity\LaravelMautic\Support\ManagerContactReaderProvider;
use Trianity\LaravelMautic\Support\ManagerContactSyncTransport;
use Trianity\LaravelMautic\Support\ManagerReconciliationSource;
use Trianity\LaravelMautic\Support\ManagerSubscriptionTransport;

final class MauticServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mautic.php', 'mautic');

        $this->registerDefaultContracts();
        $this->registerIdentity();
        $this->registerOAuth();
        $this->registerOutbox();
        $this->registerSubscription();
        $this->registerInbox();
        $this->registerReconciliation();
        $this->registerManager();
    }

    private function registerDefaultContracts(): void
    {
        $this->app->bindIf(HttpClientFactory::class, GuzzleHttpClientFactory::class);
        $this->app->bindIf(WebhookSecretProvider::class, EnvironmentWebhookSecretProvider::class);
        $this->app->bindIf(Clock::class, SystemClock::class);
        $this->app->bindIf(OAuthClientCredentialsProvider::class, EnvironmentOAuthClientCredentialsProvider::class);
        $this->app->bindIf(OAuthBrowserAuthorizer::class, DenyOAuthBrowserAuthorizer::class);
        $this->app->bindIf(OAuthTokenEndpoint::class, HttpOAuthTokenEndpoint::class);
        $this->app->bindIf(AuthenticationFactory::class, ApiLibraryAuthenticationFactory::class);
        $this->app->bindIf(ContactSyncTransport::class, ManagerContactSyncTransport::class);
        $this->app->bindIf(ContactReaderProvider::class, ManagerContactReaderProvider::class);
        $this->app->bindIf(ReconciliationSource::class, ManagerReconciliationSource::class);
        $this->app->bindIf(SubscriptionTransport::class, ManagerSubscriptionTransport::class);
    }

    private function registerIdentity(): void
    {
        $this->app->singletonIf(ContactMappingRepository::class, function ($app): ContactMappingRepository {
            $connection = $app['config']->get('mautic.identity.database_connection');
            $table = $app['config']->get('mautic.identity.mappings_table', 'mautic_contact_mappings');

            return new DatabaseContactMappingRepository(
                $app['db'],
                is_string($connection) && $connection !== '' ? $connection : null,
                is_string($table) && $table !== '' ? $table : 'mautic_contact_mappings',
            );
        });
    }

    private function registerOAuth(): void
    {
        $this->app->singleton(OAuthCallbackUrlResolver::class);
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
                $app->make(OAuthCallbackUrlResolver::class),
            );
        });
    }

    private function registerOutbox(): void
    {
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
    }

    private function registerSubscription(): void
    {
        $this->app->singleton(DatabaseSubscriptionStore::class, function ($app): DatabaseSubscriptionStore {
            $connection = $this->subscriptionDatabaseConnection();
            if ($connection !== $this->identityDatabaseConnection()
                || $connection !== $this->outboxDatabaseConnection()) {
                throw new InvalidConfiguration(
                    'Atomic subscription capture requires subscription, identity and outbox storage on the same database connection.',
                );
            }

            return new DatabaseSubscriptionStore(
                $app['db'],
                $app->make(Clock::class),
                $connection,
                $this->subscriptionTable('intents_table', 'mautic_subscription_intents'),
                $this->subscriptionTable('segments_table', 'mautic_subscription_segments'),
                $this->subscriptionTable('locks_table', 'mautic_subscription_segment_locks'),
                $this->subscriptionTable('audits_table', 'mautic_subscription_audits'),
            );
        });
        $this->app->singleton(SubscriptionDispatcher::class, function ($app): SubscriptionDispatcher {
            $queue = $app['config']->get('mautic.subscription.queue');

            return new SubscriptionDispatcher(
                $app->make(Queue::class),
                is_string($queue) && $queue !== '' ? $queue : null,
            );
        });
        $this->app->singleton(SubscriptionDelivery::class);
        $this->app->singleton(SubscriptionPoller::class);
        $this->app->singleton(SubscriptionOperator::class);
        $this->app->singleton(SubscriptionProcessor::class, function ($app): SubscriptionProcessor {
            return new SubscriptionProcessor(
                $app->make(DatabaseSubscriptionStore::class),
                $app->make(DatabaseOutboxStore::class),
                $app->make(ContactMappingRepository::class),
                $app->make(SubscriptionTransport::class),
                (int) $app['config']->get('mautic.subscription.lease_seconds', 30),
                (int) $app['config']->get('mautic.subscription.max_membership_attempts', 5),
                (int) $app['config']->get('mautic.subscription.maximum_delay_seconds', 300),
            );
        });
    }

    private function registerInbox(): void
    {
        $this->app->singleton(DatabaseWebhookInboxStore::class, function ($app): DatabaseWebhookInboxStore {
            $connection = $app['config']->get('mautic.inbox.database_connection');

            return new DatabaseWebhookInboxStore(
                $app['db'],
                $app['encrypter'],
                $app->make(Clock::class),
                is_string($connection) && $connection !== '' ? $connection : null,
                $this->inboxTable('deliveries_table', 'mautic_webhook_deliveries'),
                $this->inboxTable('events_table', 'mautic_webhook_events'),
                $this->inboxTable('locks_table', 'mautic_webhook_locks'),
            );
        });
        $this->app->singleton(WebhookInboxDispatcher::class, function ($app): WebhookInboxDispatcher {
            $queue = $app['config']->get('mautic.inbox.queue');

            return new WebhookInboxDispatcher(
                $app->make(Queue::class),
                is_string($queue) && $queue !== '' ? $queue : null,
            );
        });
        $this->app->singleton(WebhookInbox::class);
        $this->app->singleton(WebhookInboxPoller::class);
        $this->app->singleton(WebhookInboxProcessor::class, function ($app): WebhookInboxProcessor {
            return new WebhookInboxProcessor(
                $app->make(DatabaseWebhookInboxStore::class),
                $app->make(ContactMappingRepository::class),
                $app->make(InboundContactProjector::class),
                $app->make(WebhookContactMapper::class),
                $app['config'],
                (int) $app['config']->get('mautic.inbox.lease_seconds', 30),
            );
        });
    }

    private function registerReconciliation(): void
    {
        $this->app->singleton(DatabaseReconciliationStore::class, function ($app): DatabaseReconciliationStore {
            return new DatabaseReconciliationStore(
                $app['db'],
                $app->make(Clock::class),
                $this->reconciliationDatabaseConnection(),
                $this->reconciliationTable('checkpoints_table', 'mautic_reconciliation_checkpoints'),
                $this->reconciliationTable('observations_table', 'mautic_reconciliation_observations'),
                $this->reconciliationTable('findings_table', 'mautic_reconciliation_findings'),
            );
        });
        $this->app->singleton(DatabaseMappingInventory::class, function ($app): DatabaseMappingInventory {
            return new DatabaseMappingInventory(
                $app['db'],
                $app->make(ContactMappingRepository::class),
                $this->identityDatabaseConnection(),
                $this->identityTable(),
            );
        });
        $this->app->singleton(DatabaseMappingRecovery::class, function ($app): DatabaseMappingRecovery {
            if ($this->identityDatabaseConnection() !== $this->reconciliationDatabaseConnection()) {
                throw new InvalidConfiguration(
                    'Atomic mapping recovery requires reconciliation and identity storage on the same database connection.',
                );
            }

            return new DatabaseMappingRecovery(
                $app['db'],
                $app->make(Clock::class),
                $this->identityDatabaseConnection(),
                $this->identityTable(),
                $this->reconciliationTable('audits_table', 'mautic_reconciliation_audits'),
            );
        });
        $this->app->singleton(MappingRecoveryService::class);
        $this->app->singleton(ContactReconciler::class, function ($app): ContactReconciler {
            $reservationSeconds = (int) $app['config']->get('mautic.reconciliation.request_reservation_seconds', 30);
            $this->validateReconciliationReservation($app['config'], $reservationSeconds);

            return new ContactReconciler(
                $app->make(DatabaseReconciliationStore::class),
                $app->make(ReconciliationSource::class),
                $app->make(ContactMappingRepository::class),
                $app->make(Clock::class),
                (int) $app['config']->get('mautic.reconciliation.page_size', 100),
                (int) $app['config']->get('mautic.reconciliation.minimum_page_interval_seconds', 1),
                (int) $app['config']->get('mautic.reconciliation.maximum_pages_per_run', 1),
                $reservationSeconds,
            );
        });
        $this->app->singleton(DatabaseMauticDiagnostics::class);
    }

    private function registerManager(): void
    {
        $this->app->bindIf(ConnectionFactory::class, ApiLibraryConnectionFactory::class);
        $this->app->singleton(MauticManager::class);
    }

    public function boot(): void
    {
        $this->registerOAuthBrowserRoutes();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/mautic.php' => config_path('mautic.php'),
            ], 'mautic-config');
            $oauthMigration = [
                __DIR__.'/../database/migrations/create_mautic_oauth_storage.php.stub' => database_path('migrations/2026_10_19_000000_create_mautic_oauth_storage.php'),
            ];
            $this->publishes($oauthMigration, 'mautic-migrations');
            $this->publishes($oauthMigration, 'mautic-oauth-migrations');
            $identityMigration = [
                __DIR__.'/../database/migrations/create_mautic_contact_mappings.php.stub' => database_path('migrations/2026_10_19_010000_create_mautic_contact_mappings.php'),
            ];
            $this->publishes($identityMigration, 'mautic-migrations');
            $this->publishes($identityMigration, 'mautic-identity-migrations');
            $outboxMigration = [
                __DIR__.'/../database/migrations/create_mautic_contact_outbox.php.stub' => database_path('migrations/2026_10_19_020000_create_mautic_contact_outbox.php'),
            ];
            $this->publishes($outboxMigration, 'mautic-migrations');
            $this->publishes($outboxMigration, 'mautic-outbox-migrations');
            $inboxMigration = [
                __DIR__.'/../database/migrations/create_mautic_webhook_inbox.php.stub' => database_path('migrations/2026_10_19_030000_create_mautic_webhook_inbox.php'),
            ];
            $this->publishes($inboxMigration, 'mautic-migrations');
            $this->publishes($inboxMigration, 'mautic-inbox-migrations');
            $reconciliationMigration = [
                __DIR__.'/../database/migrations/create_mautic_reconciliation.php.stub' => database_path('migrations/2026_10_19_040000_create_mautic_reconciliation.php'),
            ];
            $this->publishes($reconciliationMigration, 'mautic-migrations');
            $this->publishes($reconciliationMigration, 'mautic-reconciliation-migrations');
            $subscriptionMigration = [
                __DIR__.'/../database/migrations/create_mautic_subscription_delivery.php.stub' => database_path('migrations/2026_10_19_050000_create_mautic_subscription_delivery.php'),
            ];
            $this->publishes($subscriptionMigration, 'mautic-migrations');
            $this->publishes($subscriptionMigration, 'mautic-subscription-migrations');
            $this->commands([
                ReconcileContactsCommand::class,
                ReconcileMappingsCommand::class,
                MauticDiagnosticsCommand::class,
                OAuthCallbackUrlCommand::class,
            ]);
        }
    }

    private function registerOAuthBrowserRoutes(): void
    {
        $config = $this->app->make(Repository::class);
        if ($config->get('mautic.oauth.routes.enabled', false) !== true) {
            return;
        }
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        $middleware = $config->get('mautic.oauth.routes.middleware', ['web']);
        $startName = $config->get('mautic.oauth.routes.start_name', 'mautic.oauth.start');
        $callbackName = $config->get('mautic.oauth.routes.callback_name', 'mautic.oauth.callback');
        if (! is_array($middleware) || $middleware === []
            || ! array_all($middleware, static fn (mixed $value): bool => is_string($value) && $value !== '')
            || ! is_string($startName) || $startName === ''
            || ! is_string($callbackName) || $callbackName === ''
            || $startName === $callbackName) {
            throw new InvalidConfiguration('The package OAuth route configuration is invalid.');
        }

        $prefix = $this->app->make(OAuthCallbackUrlResolver::class)->routePrefix();
        $router = $this->app->make(Router::class);
        $router->middleware($middleware)->prefix($prefix)->group(function (Router $router) use ($startName, $callbackName): void {
            $router->post('{connection}/authorize', [OAuthBrowserController::class, 'start'])->name($startName);
            $router->get('{connection}/callback', [OAuthBrowserController::class, 'callback'])->name($callbackName);
        });
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

    private function outboxDatabaseConnection(): ?string
    {
        $name = $this->app->make(Repository::class)->get('mautic.outbox.database_connection');

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function subscriptionDatabaseConnection(): ?string
    {
        $name = $this->app->make(Repository::class)->get('mautic.subscription.database_connection');

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function subscriptionTable(string $key, string $default): string
    {
        $table = $this->app->make(Repository::class)->get('mautic.subscription.'.$key, $default);

        return is_string($table) && $table !== '' ? $table : $default;
    }

    private function inboxTable(string $key, string $default): string
    {
        $table = $this->app->make(Repository::class)->get('mautic.inbox.'.$key, $default);

        return is_string($table) && $table !== '' ? $table : $default;
    }

    private function identityDatabaseConnection(): ?string
    {
        $name = $this->app->make(Repository::class)->get('mautic.identity.database_connection');

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function identityTable(): string
    {
        $table = $this->app->make(Repository::class)->get(
            'mautic.identity.mappings_table',
            'mautic_contact_mappings',
        );

        return is_string($table) && $table !== '' ? $table : 'mautic_contact_mappings';
    }

    private function reconciliationDatabaseConnection(): ?string
    {
        $name = $this->app->make(Repository::class)->get('mautic.reconciliation.database_connection');

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function reconciliationTable(string $key, string $default): string
    {
        $table = $this->app->make(Repository::class)->get('mautic.reconciliation.'.$key, $default);

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

    private function validateReconciliationReservation(Repository $config, int $reservationSeconds): void
    {
        if ($reservationSeconds < 1) {
            throw new InvalidConfiguration('The reconciliation request reservation must be positive.');
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
            if ((is_int($timeout) || is_float($timeout)) && $reservationSeconds <= $timeout) {
                throw new InvalidConfiguration(
                    'The reconciliation request reservation must be longer than every Mautic request timeout.',
                );
            }
        }
    }
}
