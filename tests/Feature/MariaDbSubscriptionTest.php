<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Identity\DatabaseContactMappingRepository;
use Trianity\LaravelMautic\Identity\LocalContactReference;
use Trianity\LaravelMautic\Outbox\ContactOutbox;
use Trianity\LaravelMautic\Outbox\DatabaseOutboxStore;
use Trianity\LaravelMautic\Outbox\OutboxDispatcher;
use Trianity\LaravelMautic\Segment\StaticSegmentDefinition;
use Trianity\LaravelMautic\Subscription\DatabaseSubscriptionStore;
use Trianity\LaravelMautic\Subscription\SegmentTarget;
use Trianity\LaravelMautic\Subscription\SubscriptionCommand;
use Trianity\LaravelMautic\Subscription\SubscriptionDelivery;
use Trianity\LaravelMautic\Subscription\SubscriptionDispatcher;
use Trianity\LaravelMautic\Write\ContactFields;

it('verifies canonical subscription storage and conditional recovery on disposable MariaDB', function () {
    $host = getenv('MAUTIC_TEST_MARIADB_HOST');
    $database = getenv('MAUTIC_TEST_MARIADB_DATABASE');
    if (! is_string($host) || $host === '' || ! is_string($database) || $database === '') {
        $this->markTestSkipped('Disposable MariaDB is not configured.');
    }
    if (! str_contains(strtolower($database), 'test')) {
        $this->fail('MAUTIC_TEST_MARIADB_DATABASE must name a disposable test database.');
    }
    config()->set('database.connections.subscription-mariadb', [
        'driver' => 'mysql', 'host' => $host,
        'port' => (int) (getenv('MAUTIC_TEST_MARIADB_PORT') ?: 3306), 'database' => $database,
        'username' => getenv('MAUTIC_TEST_MARIADB_USERNAME') ?: 'root',
        'password' => getenv('MAUTIC_TEST_MARIADB_PASSWORD') ?: '',
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
    ]);
    foreach (['identity', 'outbox', 'subscription'] as $section) {
        config()->set('mautic.'.$section.'.database_connection', 'subscription-mariadb');
    }
    DB::purge('subscription-mariadb');
    $schema = Schema::connection('subscription-mariadb');
    $tables = [
        'mautic_subscription_audits', 'mautic_subscription_segment_locks',
        'mautic_subscription_segments', 'mautic_subscription_intents',
        'mautic_outbox_connection_blocks', 'mautic_outbox_audits', 'mautic_outbox_locks',
        'mautic_outbox_commands', 'mautic_contact_mappings',
    ];
    foreach ($tables as $table) {
        $schema->dropIfExists($table);
    }

    try {
        (require dirname(__DIR__, 2).'/database/migrations/create_mautic_contact_mappings.php.stub')->up();
        (require dirname(__DIR__, 2).'/database/migrations/create_mautic_contact_outbox.php.stub')->up();
        (require dirname(__DIR__, 2).'/database/migrations/create_mautic_subscription_delivery.php.stub')->up();
        expect($schema->hasColumn('mautic_subscription_intents', 'lease_generation'))->toBeTrue()
            ->and($schema->hasColumn('mautic_subscription_intents', 'next_attempt_at'))->toBeTrue()
            ->and($schema->hasColumn('mautic_subscription_intents', 'membership_read_attempts'))->toBeTrue()
            ->and($schema->hasTable('mautic_subscription_audits'))->toBeTrue();

        $clock = new class implements Clock
        {
            public function now(): int
            {
                return 5_000;
            }
        };
        $subscription = new DatabaseSubscriptionStore(DB::getFacadeRoot(), $clock, 'subscription-mariadb');
        $outbox = new DatabaseOutboxStore(DB::getFacadeRoot(), $clock, 'subscription-mariadb');
        Queue::fake();
        $delivery = new SubscriptionDelivery(
            $subscription,
            new ContactOutbox($outbox, new OutboxDispatcher(app(QueueContract::class), $outbox)),
            new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'subscription-mariadb'),
            new SubscriptionDispatcher(app(QueueContract::class)),
        );
        $command = new SubscriptionCommand(
            'main', new LocalContactReference('mariadb-subscription', 'one'), 'accepted', 1,
            ContactFields::fromArray(['email' => 'mariadb@example.test'], ['email']),
            SegmentTarget::createIfAbsent('mariadb-key', new StaticSegmentDefinition('MariaDB test', 'mariadb-test')),
        );
        try {
            DB::connection('subscription-mariadb')->transaction(function () use ($delivery, $command): void {
                $delivery->record($command);
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }
        expect(DB::connection('subscription-mariadb')->table('mautic_subscription_intents')->count())->toBe(0)
            ->and(DB::connection('subscription-mariadb')->table('mautic_outbox_commands')->count())->toBe(0);

        $captured = DB::connection('subscription-mariadb')->transaction(fn () => $delivery->record($command));
        $claim = $subscription->begin($captured->operationId, 'mariadb-owner', 30);
        expect($claim?->generation)->toBe(1)
            ->and($subscription->begin($captured->operationId, 'loser', 30))->toBeNull();
        $provisioning = $subscription->acquireSegment('main', 'mariadb-key', $captured->operationId, 'mariadb-owner', 30);
        expect($provisioning)->not->toBeNull()
            ->and($subscription->acquireSegment('main', 'mariadb-key', 'other-operation', 'loser', 30))->toBeNull();
        $subscription->segmentCreateInFlight($provisioning);
        expect($subscription->preserveSegmentCreateEvidence($provisioning, 91, 'operator_required'))->toBeTrue();

        DB::connection('subscription-mariadb')->table('mautic_subscription_intents')
            ->where('operation_id', $captured->operationId)->update([
                'status' => 'failed', 'stage' => 'segment_ready',
                'remote_contact_id' => 42, 'remote_segment_id' => 91,
                'lease_owner' => null, 'lease_expires_at' => null,
                'last_failure_kind' => 'validation',
                'last_error_code' => 'membership_rejected_validation',
            ]);
        $subscription->operatorReplayMembership(
            $captured->operationId, 'mariadb-operator', 'definite-failure',
        );
        expect($subscription->get($captured->operationId)?->status->value)->toBe('pending')
            ->and($subscription->audits($captured->operationId))->toHaveCount(1);
    } finally {
        foreach ($tables as $table) {
            $schema->dropIfExists($table);
        }
    }
});
