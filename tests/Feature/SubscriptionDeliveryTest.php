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
use Trianity\LaravelMautic\Subscription\SubscriptionStage;
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
        ->and($schema->hasTable('mautic_subscription_segment_locks'))->toBeTrue()
        ->and($schema->hasTable('mautic_subscription_audits'))->toBeTrue()
        ->and($schema->hasColumn('mautic_subscription_intents', 'lease_generation'))->toBeTrue();
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

    (new SubscriptionOperator($store, $transport, $outbox, $mappings))
        ->resumeMembershipFailure($record->operationId, 'test-operator', 'definite-write-failure');
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

it('blocks every event for a segment key after an uncertain create', function () {
    Queue::fake();
    $history = [];
    useSubscriptionResponses([
        new Response(200, ['Content-Type' => 'application/json'], '{"total":0,"lists":[]}'),
        new ConnectException('timeout', new Request('POST', 'https://mautic.example.test/api/segments/new')),
    ], $history);
    [$delivery, $store] = subscriptionServices();
    $first = makeSubscriptionContactReady($delivery, 'ambiguous-key-first');
    $outbox = new DatabaseOutboxStore(DB::getFacadeRoot(), subscriptionClock(), 'subscription-test');
    $mappings = new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'subscription-test');
    $processor = new SubscriptionProcessor(
        $store, $outbox, $mappings, new ManagerSubscriptionTransport(app(MauticManager::class)),
    );
    $processor->process($first);

    $secondCommand = new SubscriptionCommand(
        'main', new LocalContactReference('tenant:acme:contact', '42'), 'ambiguous-key-second', 2,
        ContactFields::fromArray(['email' => 'subscriber@example.test'], ['email']),
        SegmentTarget::createIfAbsent(
            'offer-74-paid', new StaticSegmentDefinition('Offer 74 paid subscribers', 'owned-offer-74-paid'),
        ),
    );
    $second = DB::connection('subscription-test')->transaction(fn () => $delivery->record($secondCommand));
    DB::connection('subscription-test')->table('mautic_outbox_commands')
        ->where('operation_id', $second->contactOperationId)
        ->update(['status' => 'completed', 'delivery_stage' => 'remote_succeeded', 'remote_id' => 42]);
    $processor->process($second->operationId);

    expect($store->get($second->operationId)?->status)->toBe(SubscriptionStatus::Ambiguous)
        ->and($store->get($second->operationId)?->lastErrorCode)->toBe('segment_key_provisioning_ambiguous')
        ->and($history)->toHaveCount(2);
});

it('uses exact claim generations and rejects stale transitions and replaced readback', function () {
    Queue::fake();
    [$delivery, $store] = subscriptionServices();
    $record = DB::connection('subscription-test')->transaction(fn () => $delivery->record(subscriptionCommand('claim-generation')));
    $first = $store->begin($record->operationId, 'first-owner', 30);
    expect($first)->not->toBeNull();
    DB::connection('subscription-test')->table('mautic_subscription_intents')
        ->where('operation_id', $record->operationId)->update(['lease_expires_at' => 1_999]);
    $replacement = $store->begin($record->operationId, 'replacement-owner', 30);
    expect($replacement?->generation)->toBe(2)
        ->and(fn () => $store->pending(
            $first,
            SubscriptionStage::Recorded,
        ))->toThrow(RuntimeException::class);

    $otherCommand = new SubscriptionCommand(
        'main', new LocalContactReference('tenant:acme:contact', '43'), 'claim-readback', 1,
        ContactFields::fromArray(['email' => 'other@example.test'], ['email']),
        SegmentTarget::createIfAbsent(
            'other-key', new StaticSegmentDefinition('Other segment', 'other-segment'),
        ),
    );
    $other = DB::connection('subscription-test')->transaction(fn () => $delivery->record($otherCommand));
    DB::connection('subscription-test')->unprepared(<<<'SQL'
CREATE TRIGGER replace_subscription_claim AFTER UPDATE OF lease_owner ON mautic_subscription_intents
WHEN NEW.lease_owner = 'winner'
BEGIN
    UPDATE mautic_subscription_intents
    SET lease_owner = 'interloper', lease_generation = NEW.lease_generation + 1
    WHERE operation_id = NEW.operation_id;
END
SQL);
    expect($store->begin($other->operationId, 'winner', 30))->toBeNull();
});

it('retains a created remote ID when its first evidence write fails', function () {
    Queue::fake();
    useSubscriptionResponses([
        new Response(200, ['Content-Type' => 'application/json'], '{"total":0,"lists":[]}'),
        new Response(201, ['Content-Type' => 'application/json'], '{"list":{"id":73,"name":"Offer 74 paid subscribers","alias":"owned-offer-74-paid","isPublished":true,"isGlobal":true,"filters":[]}}'),
    ]);
    [$delivery, $store] = subscriptionServices();
    $operationId = makeSubscriptionContactReady($delivery, 'evidence-fallback');
    DB::connection('subscription-test')->unprepared(<<<'SQL'
CREATE TRIGGER fail_primary_segment_evidence BEFORE UPDATE OF remote_id ON mautic_subscription_segment_locks
WHEN NEW.remote_id IS NOT NULL AND NEW.last_error_code IS NULL
BEGIN
    SELECT RAISE(ABORT, 'injected first evidence failure');
END
SQL);
    (new SubscriptionProcessor(
        $store,
        new DatabaseOutboxStore(DB::getFacadeRoot(), subscriptionClock(), 'subscription-test'),
        new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'subscription-test'),
        new ManagerSubscriptionTransport(app(MauticManager::class)),
    ))->process($operationId);
    $block = $store->segmentProvisioningBlock('main', 'offer-74-paid');

    expect($store->get($operationId)?->status)->toBe(SubscriptionStatus::Ambiguous)
        ->and($store->get($operationId)?->remoteSegmentId)->toBe(73)
        ->and($block?->state)->toBe('ambiguous')
        ->and((int) $block?->remote_id)->toBe(73);
});

it('verifies and audits explicit recovery of a known created segment', function () {
    Queue::fake();
    useSubscriptionResponses([
        new Response(200, ['Content-Type' => 'application/json'], '{"total":0,"lists":[]}'),
        new Response(201, ['Content-Type' => 'application/json'], '{"list":{"id":81,"name":"Offer 74 paid subscribers","alias":"owned-offer-74-paid","isPublished":true,"isGlobal":true,"filters":[]}}'),
    ]);
    [$delivery, $store] = subscriptionServices();
    $operationId = makeSubscriptionContactReady($delivery, 'association-recovery');
    DB::connection('subscription-test')->unprepared(<<<'SQL'
CREATE TRIGGER fail_segment_association BEFORE INSERT ON mautic_subscription_segments
BEGIN
    SELECT RAISE(ABORT, 'injected association failure');
END
SQL);
    $outbox = new DatabaseOutboxStore(DB::getFacadeRoot(), subscriptionClock(), 'subscription-test');
    $mappings = new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'subscription-test');
    (new SubscriptionProcessor(
        $store, $outbox, $mappings, new ManagerSubscriptionTransport(app(MauticManager::class)),
    ))->process($operationId);
    DB::connection('subscription-test')->unprepared('DROP TRIGGER fail_segment_association');

    useSubscriptionResponses([
        new Response(200, ['Content-Type' => 'application/json'], '{"list":{"id":81,"name":"Offer 74 paid subscribers","alias":"owned-offer-74-paid","isPublished":true,"isGlobal":true,"filters":[]}}'),
    ]);
    app()->forgetInstance(MauticManager::class);
    (new SubscriptionOperator(
        $store, new ManagerSubscriptionTransport(app(MauticManager::class)), $outbox, $mappings,
    ))->associateAmbiguousCreate(
        $operationId, 81, 'test-operator', 'verified-owned-segment', true,
    );

    expect($store->associatedSegment('main', 'offer-74-paid'))->toBe(81)
        ->and($store->segmentProvisioningBlock('main', 'offer-74-paid'))->toBeNull()
        ->and($store->get($operationId)?->status)->toBe(SubscriptionStatus::Pending)
        ->and($store->audits($operationId))->toHaveCount(1)
        ->and($store->audits($operationId)[0]->actor)->toBe('test-operator');
});

it('verifies repaired contact prerequisites before audited resume', function () {
    Queue::fake();
    [$delivery, $store] = subscriptionServices();
    $operationId = makeSubscriptionContactReady($delivery, 'contact-recovery');
    DB::connection('subscription-test')->table('mautic_subscription_intents')
        ->where('operation_id', $operationId)->update([
            'status' => 'ambiguous', 'stage' => 'recorded', 'last_error_code' => 'contact_ambiguous',
        ]);
    $outbox = new DatabaseOutboxStore(DB::getFacadeRoot(), subscriptionClock(), 'subscription-test');
    $mappings = new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'subscription-test');
    (new SubscriptionOperator(
        $store, new ManagerSubscriptionTransport(app(MauticManager::class)), $outbox, $mappings,
    ))->resumeContactPrerequisite($operationId, 'test-operator', 'contact-repaired');

    expect($store->get($operationId)?->status)->toBe(SubscriptionStatus::Pending)
        ->and($store->get($operationId)?->stage->value)->toBe('contact_ready')
        ->and($store->get($operationId)?->remoteContactId)->toBe(42)
        ->and($store->audits($operationId)[0]->outcome)->toBe('contact_prerequisite_verified');
});

it('completes membership uncertainty only after observing the exact remote membership', function () {
    Queue::fake();
    useSubscriptionResponses([
        new Response(200, ['Content-Type' => 'application/json'], '{"list":{"id":9,"name":"Existing","alias":"existing","isPublished":true,"isGlobal":true,"filters":[]}}'),
        new Response(200, ['Content-Type' => 'application/json'], '{"total":0,"lists":[]}'),
        new Response(200, ['Content-Type' => 'application/json'], '{"success":1}'),
        new Response(200, ['Content-Type' => 'application/json'], '{"total":0,"lists":[]}'),
    ]);
    [$delivery, $store] = subscriptionServices();
    $command = new SubscriptionCommand(
        'main', new LocalContactReference('tenant:acme:contact', '42'), 'membership-confirm', 1,
        ContactFields::fromArray(['email' => 'subscriber@example.test'], ['email']),
        SegmentTarget::existing('membership-confirm-segment', 9),
    );
    $record = DB::connection('subscription-test')->transaction(fn () => $delivery->record($command));
    DB::connection('subscription-test')->table('mautic_outbox_commands')
        ->where('operation_id', $record->contactOperationId)
        ->update(['status' => 'completed', 'delivery_stage' => 'remote_succeeded', 'remote_id' => 42]);
    $mappings = new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'subscription-test');
    $mappings->claim('main', new LocalContactReference('tenant:acme:contact', '42'), 42);
    $outbox = new DatabaseOutboxStore(DB::getFacadeRoot(), subscriptionClock(), 'subscription-test');
    (new SubscriptionProcessor(
        $store, $outbox, $mappings, new ManagerSubscriptionTransport(app(MauticManager::class)),
    ))->process($record->operationId);
    expect($store->get($record->operationId)?->lastErrorCode)->toBe('membership_not_verified');

    useSubscriptionResponses([
        new Response(200, ['Content-Type' => 'application/json'], '{"total":1,"lists":{"9":{"id":9,"name":"Existing","alias":"existing"}}}'),
    ]);
    app()->forgetInstance(MauticManager::class);
    (new SubscriptionOperator(
        $store, new ManagerSubscriptionTransport(app(MauticManager::class)), $outbox, $mappings,
    ))->confirmMembership($record->operationId, 'test-operator', 'membership-observed');

    expect($store->get($record->operationId)?->status)->toBe(SubscriptionStatus::Completed)
        ->and($store->audits($record->operationId)[0]->outcome)->toBe('remote_membership_verified');
});

it('durably delays a transient membership preflight read without sending a mutation', function () {
    Queue::fake();
    $history = [];
    useSubscriptionResponses([
        new Response(200, ['Content-Type' => 'application/json'], '{"total":1,"lists":{"9":{"id":9,"name":"Offer 74 paid subscribers","alias":"owned-offer-74-paid","isPublished":true,"isGlobal":true,"filters":[]}}}'),
        new Response(503, ['Content-Type' => 'application/json', 'Retry-After' => '7'], '{"errors":[{"code":503,"message":"busy"}]}'),
        new Response(200, ['Content-Type' => 'application/json'], '{"total":0,"lists":[]}'),
        new Response(200, ['Content-Type' => 'application/json'], '{"success":1}'),
        new Response(200, ['Content-Type' => 'application/json'], '{"total":1,"lists":{"9":{"id":9,"name":"Existing","alias":"existing"}}}'),
    ], $history);
    [$delivery] = subscriptionServices();
    $operationId = makeSubscriptionContactReady($delivery, 'membership-preflight-retry');
    $clock = new class implements Clock
    {
        public int $time = 2_000;

        public function now(): int
        {
            return $this->time;
        }
    };
    $store = new DatabaseSubscriptionStore(DB::getFacadeRoot(), $clock, 'subscription-test');
    $processor = new SubscriptionProcessor(
        $store,
        new DatabaseOutboxStore(DB::getFacadeRoot(), $clock, 'subscription-test'),
        new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'subscription-test'),
        new ManagerSubscriptionTransport(app(MauticManager::class)),
        30,
        3,
    );

    $processor->process($operationId);
    $waiting = $store->get($operationId);
    expect($waiting?->lastErrorCode)->toBe('membership_preflight_remote_server')
        ->and($waiting?->status)->toBe(SubscriptionStatus::RetryWait)
        ->and($waiting?->stage)->toBe(SubscriptionStage::SegmentReady)
        ->and($waiting?->nextAttemptAt)->toBe(2_007)
        ->and($waiting?->membershipReadAttempts)->toBe(1)
        ->and(array_column(array_map(static fn ($entry) => [
            'method' => $entry['request']->getMethod(),
        ], $history), 'method'))->toBe(['GET', 'GET']);

    $processor->process($operationId);
    expect($history)->toHaveCount(2)
        ->and($store->recoverable(10))->toBe([]);

    $clock->time = 2_007;
    expect($store->recoverable(10))->toBe([$operationId]);
    $processor->process($operationId);

    expect($store->get($operationId)?->lastErrorCode)->toBeNull()
        ->and($store->get($operationId)?->status)->toBe(SubscriptionStatus::Completed)
        ->and(array_map(static fn ($entry) => $entry['request']->getMethod(), $history))
        ->toBe(['GET', 'GET', 'GET', 'POST', 'GET'])
        ->and(DB::connection('subscription-test')->table('mautic_outbox_commands')->count())->toBe(1)
        ->and(DB::connection('subscription-test')->table('mautic_subscription_segments')->count())->toBe(1);
});

it('makes a permanent membership preflight failure actionable and recoverable without attestation', function () {
    Queue::fake();
    $history = [];
    useSubscriptionResponses([
        new Response(200, ['Content-Type' => 'application/json'], '{"total":1,"lists":{"9":{"id":9,"name":"Offer 74 paid subscribers","alias":"owned-offer-74-paid","isPublished":true,"isGlobal":true,"filters":[]}}}'),
        new Response(400, ['Content-Type' => 'application/json'], '{"errors":[{"code":400,"message":"invalid read"}]}'),
    ], $history);
    [$delivery, $store] = subscriptionServices();
    $operationId = makeSubscriptionContactReady($delivery, 'membership-preflight-permanent');
    $outbox = new DatabaseOutboxStore(DB::getFacadeRoot(), subscriptionClock(), 'subscription-test');
    $mappings = new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'subscription-test');
    $transport = new ManagerSubscriptionTransport(app(MauticManager::class));
    (new SubscriptionProcessor($store, $outbox, $mappings, $transport))->process($operationId);

    $failed = $store->get($operationId);
    expect($failed?->status)->toBe(SubscriptionStatus::Failed)
        ->and($failed?->stage)->toBe(SubscriptionStage::SegmentReady)
        ->and($failed?->lastFailureKind)->toBe('validation')
        ->and($failed?->lastErrorCode)->toBe('membership_preflight_validation')
        ->and($failed?->membershipReadAttempts)->toBe(1)
        ->and(array_map(static fn ($entry) => $entry['request']->getMethod(), $history))->toBe(['GET', 'GET']);

    (new SubscriptionOperator($store, $transport, $outbox, $mappings))
        ->resumeMembershipFailure($operationId, 'test-operator', 'preflight-fixed');
    useSubscriptionResponses([
        new Response(200, ['Content-Type' => 'application/json'], '{"total":0,"lists":[]}'),
        new Response(200, ['Content-Type' => 'application/json'], '{"success":1}'),
        new Response(200, ['Content-Type' => 'application/json'], '{"total":1,"lists":{"9":{"id":9,"name":"Existing","alias":"existing"}}}'),
    ]);
    app()->forgetInstance(MauticManager::class);
    (new SubscriptionProcessor(
        $store, $outbox, $mappings, new ManagerSubscriptionTransport(app(MauticManager::class)),
    ))->process($operationId);

    expect($store->get($operationId)?->status)->toBe(SubscriptionStatus::Completed)
        ->and(DB::connection('subscription-test')->table('mautic_outbox_commands')->count())->toBe(1)
        ->and(DB::connection('subscription-test')->table('mautic_subscription_segments')->count())->toBe(1);
});

it('distinguishes rejected membership writes from possibly applied responses', function (Response $response, SubscriptionStatus $status, string $code) {
    Queue::fake();
    $history = [];
    useSubscriptionResponses([
        new Response(200, ['Content-Type' => 'application/json'], '{"total":1,"lists":{"9":{"id":9,"name":"Offer 74 paid subscribers","alias":"owned-offer-74-paid","isPublished":true,"isGlobal":true,"filters":[]}}}'),
        new Response(200, ['Content-Type' => 'application/json'], '{"total":0,"lists":[]}'),
        $response,
    ], $history);
    [$delivery, $store] = subscriptionServices();
    $operationId = makeSubscriptionContactReady($delivery, 'membership-classification-'.$code);
    $outbox = new DatabaseOutboxStore(DB::getFacadeRoot(), subscriptionClock(), 'subscription-test');
    $mappings = new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'subscription-test');
    $transport = new ManagerSubscriptionTransport(app(MauticManager::class));
    (new SubscriptionProcessor($store, $outbox, $mappings, $transport))->process($operationId);

    expect($store->get($operationId)?->status)->toBe($status)
        ->and($store->get($operationId)?->lastErrorCode)->toBe($code)
        ->and(array_map(static fn ($entry) => $entry['request']->getMethod(), $history))->toBe(['GET', 'GET', 'POST']);
    if ($status === SubscriptionStatus::Failed) {
        (new SubscriptionOperator($store, $transport, $outbox, $mappings))
            ->resumeMembershipFailure($operationId, 'test-operator', 'proved-rejection');
        expect($store->get($operationId)?->status)->toBe(SubscriptionStatus::Pending);
    } else {
        expect(fn () => (new SubscriptionOperator($store, $transport, $outbox, $mappings))
            ->resumeMembershipFailure($operationId, 'test-operator', 'not-proved'))
            ->toThrow(RuntimeException::class);
    }
})->with([
    'definite validation rejection' => [
        new Response(422, ['Content-Type' => 'application/json'], '{"errors":[{"code":422,"message":"rejected"}]}'),
        SubscriptionStatus::Failed,
        'membership_rejected_validation',
    ],
    'server response is uncertain' => [
        new Response(503, ['Content-Type' => 'application/json'], '{"errors":[{"code":503,"message":"failed"}]}'),
        SubscriptionStatus::Ambiguous,
        'membership_mutation_remote_server',
    ],
    'malformed response is uncertain' => [
        new Response(200, ['Content-Type' => 'application/json'], '{"errors":"unusable"}'),
        SubscriptionStatus::Ambiguous,
        'membership_mutation_unexpected_response',
    ],
]);

it('uses read-only recovery after membership verification fails', function () {
    Queue::fake();
    $history = [];
    useSubscriptionResponses([
        new Response(200, ['Content-Type' => 'application/json'], '{"total":1,"lists":{"9":{"id":9,"name":"Offer 74 paid subscribers","alias":"owned-offer-74-paid","isPublished":true,"isGlobal":true,"filters":[]}}}'),
        new Response(200, ['Content-Type' => 'application/json'], '{"total":0,"lists":[]}'),
        new Response(200, ['Content-Type' => 'application/json'], '{"success":1}'),
        new Response(503, ['Content-Type' => 'application/json'], '{"errors":[{"code":503,"message":"busy"}]}'),
    ], $history);
    [$delivery, $store] = subscriptionServices();
    $operationId = makeSubscriptionContactReady($delivery, 'membership-verification-read');
    $outbox = new DatabaseOutboxStore(DB::getFacadeRoot(), subscriptionClock(), 'subscription-test');
    $mappings = new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'subscription-test');
    (new SubscriptionProcessor(
        $store, $outbox, $mappings, new ManagerSubscriptionTransport(app(MauticManager::class)),
    ))->process($operationId);

    expect($store->get($operationId)?->status)->toBe(SubscriptionStatus::Ambiguous)
        ->and($store->get($operationId)?->lastErrorCode)->toBe('membership_verification_remote_server')
        ->and(array_map(static fn ($entry) => $entry['request']->getMethod(), $history))
        ->toBe(['GET', 'GET', 'POST', 'GET']);

    $confirmationHistory = [];
    useSubscriptionResponses([
        new Response(200, ['Content-Type' => 'application/json'], '{"total":1,"lists":{"9":{"id":9,"name":"Existing","alias":"existing"}}}'),
    ], $confirmationHistory);
    app()->forgetInstance(MauticManager::class);
    (new SubscriptionOperator(
        $store, new ManagerSubscriptionTransport(app(MauticManager::class)), $outbox, $mappings,
    ))->confirmMembership($operationId, 'test-operator', 'membership-observed');

    expect($store->get($operationId)?->status)->toBe(SubscriptionStatus::Completed)
        ->and(array_map(static fn ($entry) => $entry['request']->getMethod(), $confirmationHistory))->toBe(['GET']);
});
