<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Psr\Http\Client\ClientInterface;
use Trianity\LaravelMautic\Configuration\ConnectionConfig;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Contracts\HttpClientFactory;
use Trianity\LaravelMautic\Exceptions\SubscriptionConflict;
use Trianity\LaravelMautic\Identity\DatabaseContactMappingRepository;
use Trianity\LaravelMautic\Identity\LocalContactReference;
use Trianity\LaravelMautic\Jobs\ProcessContactOutboxJob;
use Trianity\LaravelMautic\Jobs\ProcessSubscriptionJob;
use Trianity\LaravelMautic\MauticManager;
use Trianity\LaravelMautic\MauticServiceProvider;
use Trianity\LaravelMautic\Outbox\ContactOutbox;
use Trianity\LaravelMautic\Outbox\DatabaseOutboxStore;
use Trianity\LaravelMautic\Outbox\OutboxDispatcher;
use Trianity\LaravelMautic\Segment\StaticSegmentDefinition;
use Trianity\LaravelMautic\Subscription\DatabaseSubscriptionStore;
use Trianity\LaravelMautic\Subscription\SegmentTarget;
use Trianity\LaravelMautic\Subscription\SubscriptionCommand;
use Trianity\LaravelMautic\Subscription\SubscriptionDelivery;
use Trianity\LaravelMautic\Subscription\SubscriptionDispatcher;
use Trianity\LaravelMautic\Subscription\SubscriptionOperator;
use Trianity\LaravelMautic\Subscription\SubscriptionPoller;
use Trianity\LaravelMautic\Subscription\SubscriptionProcessor;
use Trianity\LaravelMautic\Subscription\SubscriptionStatus;
use Trianity\LaravelMautic\Support\ManagerSubscriptionTransport;
use Trianity\LaravelMautic\Write\ContactFields;

function subscriptionClock(): Clock
{
    return new class implements Clock
    {
        public function now(): int
        {
            return 2_000;
        }
    };
}

function subscriptionCommand(string $event = 'accepted-01', string $segmentKey = 'offer-74-paid'): SubscriptionCommand
{
    return new SubscriptionCommand(
        'main',
        new LocalContactReference('tenant:acme:contact', '42'),
        $event,
        1,
        ContactFields::fromArray(['email' => 'subscriber@example.test', 'firstname' => 'Ada'], ['email', 'firstname']),
        SegmentTarget::createIfAbsent(
            $segmentKey,
            new StaticSegmentDefinition('Offer 74 paid subscribers', 'owned-offer-74-paid'),
        ),
    );
}

beforeEach(function () {
    config()->set('database.default', 'subscription-test');
    config()->set('database.connections.subscription-test', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    foreach (['identity', 'outbox', 'subscription'] as $section) {
        config()->set('mautic.'.$section.'.database_connection', 'subscription-test');
    }
    DB::purge('subscription-test');
    $this->artisan('vendor:publish', ['--tag' => 'mautic-identity-migrations', '--force' => true])->assertSuccessful();
    $this->artisan('vendor:publish', ['--tag' => 'mautic-outbox-migrations', '--force' => true])->assertSuccessful();
    $this->artisan('vendor:publish', ['--tag' => 'mautic-subscription-migrations', '--force' => true])->assertSuccessful();
    (require database_path('migrations/2026_10_19_010000_create_mautic_contact_mappings.php'))->up();
    (require database_path('migrations/2026_10_19_020000_create_mautic_contact_outbox.php'))->up();
    (require database_path('migrations/2026_10_19_050000_create_mautic_subscription_delivery.php'))->up();
});

function subscriptionServices(): array
{
    $clock = subscriptionClock();
    $outboxStore = new DatabaseOutboxStore(DB::getFacadeRoot(), $clock, 'subscription-test');
    $subscriptionStore = new DatabaseSubscriptionStore(DB::getFacadeRoot(), $clock, 'subscription-test');
    $queue = app(QueueContract::class);
    $contactDispatcher = new OutboxDispatcher($queue, $outboxStore);
    $subscriptionDispatcher = new SubscriptionDispatcher($queue);
    $delivery = new SubscriptionDelivery(
        $subscriptionStore,
        new ContactOutbox($outboxStore, $contactDispatcher),
        new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'subscription-test'),
        $subscriptionDispatcher,
    );

    return [$delivery, $subscriptionStore, $subscriptionDispatcher];
}

function useSubscriptionResponses(array $responses, array &$history = []): void
{
    $handler = HandlerStack::create(new MockHandler($responses));
    $handler->push(Middleware::history($history));
    $http = new Client(['handler' => $handler]);
    app()->instance(HttpClientFactory::class, new class($http) implements HttpClientFactory
    {
        public function __construct(private ClientInterface $client) {}

        public function make(ConnectionConfig $config): ClientInterface
        {
            return $this->client;
        }
    });
    config()->set('mautic.connections.main', [
        'url' => 'https://mautic.example.test',
        'username' => 'subscription',
        'password' => 'secret',
    ]);
}

function makeSubscriptionContactReady(SubscriptionDelivery $delivery, string $event = 'accepted-01'): string
{
    $record = DB::connection('subscription-test')->transaction(fn () => $delivery->record(subscriptionCommand($event)));
    DB::connection('subscription-test')->table('mautic_outbox_commands')
        ->where('operation_id', $record->contactOperationId)
        ->update(['status' => 'completed', 'delivery_stage' => 'remote_succeeded', 'remote_id' => 42]);
    (new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'subscription-test'))
        ->claim('main', new LocalContactReference('tenant:acme:contact', '42'), 42);

    return $record->operationId;
}

it('publishes one canonical reversible subscription migration', function () {
    $published = MauticServiceProvider::pathsToPublish(MauticServiceProvider::class, 'mautic-subscription-migrations');
    $schema = Schema::connection('subscription-test');

    expect($published)->toHaveCount(1)
        ->and(array_key_first($published))->toEndWith('create_mautic_subscription_delivery.php.stub')
        ->and($schema->hasTable('mautic_subscription_intents'))->toBeTrue()
        ->and($schema->hasTable('mautic_subscription_segments'))->toBeTrue()
        ->and($schema->hasTable('mautic_subscription_segment_locks'))->toBeTrue();
});

it('requires the host transaction and rolls both contact and delivery intent back', function () {
    Queue::fake();
    [$delivery] = subscriptionServices();

    expect(fn () => $delivery->record(subscriptionCommand()))->toThrow(RuntimeException::class);
    try {
        DB::connection('subscription-test')->transaction(function () use ($delivery): void {
            $delivery->record(subscriptionCommand());
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(DB::connection('subscription-test')->table('mautic_subscription_intents')->count())->toBe(0)
        ->and(DB::connection('subscription-test')->table('mautic_outbox_commands')->count())->toBe(0)
        ->and(Queue::pushed(ProcessContactOutboxJob::class))->toHaveCount(0)
        ->and(Queue::pushed(ProcessSubscriptionJob::class))->toHaveCount(0);
});

it('records atomically dispatches after commit and recovers a lost subscription dispatch', function () {
    Queue::fake();
    [$delivery, $store, $dispatcher] = subscriptionServices();
    DB::connection('subscription-test')->transaction(fn () => $delivery->record(subscriptionCommand()));

    expect(DB::connection('subscription-test')->table('mautic_subscription_intents')->count())->toBe(1)
        ->and(DB::connection('subscription-test')->table('mautic_outbox_commands')->count())->toBe(1)
        ->and(Queue::pushed(ProcessContactOutboxJob::class))->toHaveCount(1)
        ->and(Queue::pushed(ProcessSubscriptionJob::class))->toHaveCount(1);

    expect((new SubscriptionPoller($store, $dispatcher))->recover())->toBe(1);
    expect(Queue::pushed(ProcessSubscriptionJob::class))->toHaveCount(2);
});

it('deduplicates an identical event and rejects conflicting semantic reuse', function () {
    Queue::fake();
    [$delivery] = subscriptionServices();
    DB::connection('subscription-test')->transaction(function () use ($delivery): void {
        $first = $delivery->record(subscriptionCommand());
        $second = $delivery->record(subscriptionCommand());
        expect($second->operationId)->toBe($first->operationId);
        $reordered = $delivery->record(new SubscriptionCommand(
            'main',
            new LocalContactReference('tenant:acme:contact', '42'),
            'accepted-01',
            1,
            ContactFields::fromArray(['firstname' => 'Ada', 'email' => 'subscriber@example.test'], ['firstname', 'email']),
            SegmentTarget::createIfAbsent(
                'offer-74-paid',
                new StaticSegmentDefinition('Offer 74 paid subscribers', 'owned-offer-74-paid'),
            ),
        ));
        expect($reordered->operationId)->toBe($first->operationId);
    });

    expect(DB::connection('subscription-test')->table('mautic_subscription_intents')->count())->toBe(1)
        ->and(DB::connection('subscription-test')->table('mautic_outbox_commands')->count())->toBe(1)
        ->and(Queue::pushed(ProcessSubscriptionJob::class))->toHaveCount(1);

    expect(fn () => DB::connection('subscription-test')->transaction(
        fn () => $delivery->record(subscriptionCommand(segmentKey: 'different-segment')),
    ))->toThrow(SubscriptionConflict::class);
});

it('isolates persisted segment associations by connection and stable key', function () {
    [, $store] = subscriptionServices();
    $store->associateSegment('main', 'same-key', 9, str_repeat('a', 64));
    $store->associateSegment('secondary', 'same-key', 10, str_repeat('b', 64));

    expect($store->associatedSegment('main', 'same-key'))->toBe(9)
        ->and($store->associatedSegment('secondary', 'same-key'))->toBe(10);
});

it('resumes after the contact step and verifies membership in an existing static segment', function () {
    Queue::fake();
    $history = [];
    useSubscriptionResponses([
        new Response(200, ['Content-Type' => 'application/json'], json_encode(['list' => [
            'id' => 9, 'name' => 'Existing', 'alias' => 'existing', 'isPublished' => true, 'isGlobal' => true, 'filters' => [],
        ]], JSON_THROW_ON_ERROR)),
        new Response(200, ['Content-Type' => 'application/json'], '{"total":0,"lists":[]}'),
        new Response(200, ['Content-Type' => 'application/json'], '{"success":1}'),
        new Response(200, ['Content-Type' => 'application/json'], '{"total":1,"lists":{"9":{"id":9,"name":"Existing","alias":"existing"}}}'),
    ], $history);
    [$delivery, $store] = subscriptionServices();
    $command = new SubscriptionCommand(
        'main', new LocalContactReference('tenant:acme:contact', '42'), 'existing-event', 1,
        ContactFields::fromArray(['email' => 'subscriber@example.test'], ['email']),
        SegmentTarget::existing('existing-key', 9),
    );
    $record = DB::connection('subscription-test')->transaction(fn () => $delivery->record($command));
    DB::connection('subscription-test')->table('mautic_outbox_commands')->where('operation_id', $record->contactOperationId)
        ->update(['status' => 'completed', 'delivery_stage' => 'remote_succeeded', 'remote_id' => 42]);
    (new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'subscription-test'))
        ->claim('main', new LocalContactReference('tenant:acme:contact', '42'), 42);

    (new SubscriptionProcessor(
        $store,
        new DatabaseOutboxStore(DB::getFacadeRoot(), subscriptionClock(), 'subscription-test'),
        new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'subscription-test'),
        new ManagerSubscriptionTransport(app(MauticManager::class)),
    ))->process($record->operationId);

    expect($store->get($record->operationId)?->status)->toBe(SubscriptionStatus::Completed)
        ->and($store->associatedSegment('main', 'existing-key'))->toBe(9)
        ->and(array_map(static fn ($entry) => $entry['request']->getMethod(), $history))->toBe(['GET', 'GET', 'POST', 'GET'])
        ->and((string) $history[2]['request']->getUri())->toEndWith('/api/segments/9/contact/42/add');
});

it('creates one missing owned static segment then associates and uses it', function () {
    Queue::fake();
    $history = [];
    $created = ['id' => 11, 'name' => 'Offer 74 paid subscribers', 'alias' => 'owned-offer-74-paid', 'isPublished' => true, 'isGlobal' => true, 'filters' => []];
    useSubscriptionResponses([
        new Response(200, ['Content-Type' => 'application/json'], '{"total":0,"lists":[]}'),
        new Response(201, ['Content-Type' => 'application/json'], json_encode(['list' => $created], JSON_THROW_ON_ERROR)),
        new Response(200, ['Content-Type' => 'application/json'], '{"total":0,"lists":[]}'),
        new Response(200, ['Content-Type' => 'application/json'], '{"success":1}'),
        new Response(200, ['Content-Type' => 'application/json'], '{"total":1,"lists":{"11":{"id":11,"name":"Owned","alias":"owned-offer-74-paid"}}}'),
    ], $history);
    [$delivery, $store] = subscriptionServices();
    $operationId = makeSubscriptionContactReady($delivery, 'create-segment-event');

    (new SubscriptionProcessor(
        $store,
        new DatabaseOutboxStore(DB::getFacadeRoot(), subscriptionClock(), 'subscription-test'),
        new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'subscription-test'),
        new ManagerSubscriptionTransport(app(MauticManager::class)),
    ))->process($operationId);

    expect($store->get($operationId)?->status)->toBe(SubscriptionStatus::Completed)
        ->and($store->associatedSegment('main', 'offer-74-paid'))->toBe(11)
        ->and(array_filter($history, static fn ($entry) => $entry['request']->getMethod() === 'POST'))->toHaveCount(2);
});

it('blocks missing dynamic and unpublished existing-only targets without mutation', function (Response $segmentResponse, string $expectedCode) {
    Queue::fake();
    $history = [];
    useSubscriptionResponses([$segmentResponse], $history);
    [$delivery, $store] = subscriptionServices();
    $command = new SubscriptionCommand(
        'main', new LocalContactReference('tenant:acme:contact', '42'), 'rejected-'.$expectedCode, 1,
        ContactFields::fromArray(['email' => 'subscriber@example.test'], ['email']),
        SegmentTarget::existing('rejected-'.$expectedCode, 99),
    );
    $record = DB::connection('subscription-test')->transaction(fn () => $delivery->record($command));
    DB::connection('subscription-test')->table('mautic_outbox_commands')->where('operation_id', $record->contactOperationId)
        ->update(['status' => 'completed', 'delivery_stage' => 'remote_succeeded', 'remote_id' => 42]);
    (new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'subscription-test'))
        ->claim('main', new LocalContactReference('tenant:acme:contact', '42'), 42);
    (new SubscriptionProcessor(
        $store,
        new DatabaseOutboxStore(DB::getFacadeRoot(), subscriptionClock(), 'subscription-test'),
        new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'subscription-test'),
        new ManagerSubscriptionTransport(app(MauticManager::class)),
    ))->process($record->operationId);

    expect($store->get($record->operationId)?->status)->toBe(SubscriptionStatus::Blocked)
        ->and($store->get($record->operationId)?->lastErrorCode)->toBe($expectedCode)
        ->and($history)->toHaveCount(1)
        ->and($history[0]['request']->getMethod())->toBe('GET');
})->with([
    'missing' => [new Response(404, ['Content-Type' => 'application/json'], '{"errors":[{"code":404,"message":"not found"}]}'), 'segment_missing'],
    'dynamic' => [new Response(200, ['Content-Type' => 'application/json'], '{"list":{"id":99,"name":"Dynamic","alias":"dynamic","isPublished":true,"isGlobal":true,"filters":[{"field":"email"}]}}'), 'segment_not_published_static'],
    'unpublished' => [new Response(200, ['Content-Type' => 'application/json'], '{"list":{"id":99,"name":"Draft","alias":"draft","isPublished":false,"isGlobal":true,"filters":[]}}'), 'segment_not_published_static'],
]);

it('retains completed contact work and explicitly resumes a definite membership failure', function () {
    Queue::fake();
    useSubscriptionResponses([
        new Response(200, ['Content-Type' => 'application/json'], '{"list":{"id":9,"name":"Existing","alias":"existing","isPublished":true,"isGlobal":true,"filters":[]}}'),
        new Response(200, ['Content-Type' => 'application/json'], '{"total":0,"lists":[]}'),
        new Response(422, ['Content-Type' => 'application/json'], '{"errors":[{"code":422,"message":"rejected"}]}'),
    ]);
    [$delivery, $store] = subscriptionServices();
    $command = new SubscriptionCommand(
        'main', new LocalContactReference('tenant:acme:contact', '42'), 'membership-resume', 1,
        ContactFields::fromArray(['email' => 'subscriber@example.test'], ['email']),
        SegmentTarget::existing('resume-segment', 9),
    );
    $record = DB::connection('subscription-test')->transaction(fn () => $delivery->record($command));
    DB::connection('subscription-test')->table('mautic_outbox_commands')->where('operation_id', $record->contactOperationId)
        ->update(['status' => 'completed', 'delivery_stage' => 'remote_succeeded', 'remote_id' => 42]);
    $mappings = new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'subscription-test');
    $mappings->claim('main', new LocalContactReference('tenant:acme:contact', '42'), 42);
    $outbox = new DatabaseOutboxStore(DB::getFacadeRoot(), subscriptionClock(), 'subscription-test');
    $transport = new ManagerSubscriptionTransport(app(MauticManager::class));
    (new SubscriptionProcessor($store, $outbox, $mappings, $transport))->process($record->operationId);
    expect($store->get($record->operationId)?->status)->toBe(SubscriptionStatus::Failed)
        ->and($outbox->get($record->contactOperationId)?->status->value)->toBe('completed');

    (new SubscriptionOperator($store, $transport))->resumeMembershipFailure($record->operationId);
    useSubscriptionResponses([
        new Response(200, ['Content-Type' => 'application/json'], '{"total":0,"lists":[]}'),
        new Response(200, ['Content-Type' => 'application/json'], '{"success":1}'),
        new Response(200, ['Content-Type' => 'application/json'], '{"total":1,"lists":{"9":{"id":9,"name":"Existing","alias":"existing"}}}'),
    ]);
    app()->forgetInstance(MauticManager::class);
    $resumedTransport = new ManagerSubscriptionTransport(app(MauticManager::class));
    (new SubscriptionProcessor($store, $outbox, $mappings, $resumedTransport))->process($record->operationId);

    expect($store->get($record->operationId)?->status)->toBe(SubscriptionStatus::Completed)
        ->and(DB::connection('subscription-test')->table('mautic_outbox_commands')->count())->toBe(1);
});

it('blocks an uncertain segment create without blind recreation', function () {
    Queue::fake();
    $history = [];
    useSubscriptionResponses([
        new Response(200, ['Content-Type' => 'application/json'], '{"total":0,"lists":[]}'),
        new ConnectException('timeout', new Request('POST', 'https://mautic.example.test/api/segments/new')),
    ], $history);
    [$delivery, $store] = subscriptionServices();
    $operationId = makeSubscriptionContactReady($delivery, 'ambiguous-segment');
    $processor = new SubscriptionProcessor(
        $store,
        new DatabaseOutboxStore(DB::getFacadeRoot(), subscriptionClock(), 'subscription-test'),
        new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'subscription-test'),
        new ManagerSubscriptionTransport(app(MauticManager::class)),
    );
    $processor->process($operationId);
    $processor->process($operationId);

    expect($store->get($operationId)?->status)->toBe(SubscriptionStatus::Ambiguous)
        ->and($store->get($operationId)?->lastErrorCode)->toBe('segment_create_outcome_ambiguous')
        ->and($history)->toHaveCount(2);
});
