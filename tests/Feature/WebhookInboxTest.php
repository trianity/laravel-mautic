<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Contracts\ContactMappingRepository;
use Trianity\LaravelMautic\Contracts\InboundContactProjector;
use Trianity\LaravelMautic\Contracts\WebhookSecretProvider;
use Trianity\LaravelMautic\Exceptions\WebhookIngressException;
use Trianity\LaravelMautic\Fields\FieldMapper;
use Trianity\LaravelMautic\Fields\FieldOwnership;
use Trianity\LaravelMautic\Fields\FieldRule;
use Trianity\LaravelMautic\Fields\HostContactFields;
use Trianity\LaravelMautic\Identity\ContactMapping;
use Trianity\LaravelMautic\Identity\DatabaseContactMappingRepository;
use Trianity\LaravelMautic\Identity\LocalContactReference;
use Trianity\LaravelMautic\Identity\RemoteContactState;
use Trianity\LaravelMautic\Inbox\DatabaseWebhookInboxStore;
use Trianity\LaravelMautic\Inbox\WebhookEventContext;
use Trianity\LaravelMautic\Inbox\WebhookEventStatus;
use Trianity\LaravelMautic\Inbox\WebhookInbox;
use Trianity\LaravelMautic\Inbox\WebhookInboxProcessor;
use Trianity\LaravelMautic\Inbox\WebhookIngressFailureKind;
use Trianity\LaravelMautic\Jobs\ProcessWebhookInboxJob;

final class MutableWebhookClock implements Clock
{
    public function __construct(public int $timestamp = 1_000) {}

    public function now(): int
    {
        return $this->timestamp;
    }
}

final class TestInboundContactProjector implements InboundContactProjector
{
    /** @var array<string, array<string, mixed>> */
    public array $values = [];

    /** @var list<WebhookEventContext> */
    public array $contexts = [];

    public function snapshot(ContactMapping $mapping): HostContactFields
    {
        return HostContactFields::fromArray(
            $this->values[$mapping->connection] ?? ['first_name' => 'Ada', 'last_name' => 'Lovelace'],
            ['first_name', 'last_name'],
        );
    }

    public function mapper(ContactMapping $mapping): FieldMapper
    {
        return new FieldMapper(
            new FieldRule('first_name', 'firstname', FieldOwnership::Mautic),
            new FieldRule('last_name', 'lastname', FieldOwnership::Host),
        );
    }

    public function apply(ContactMapping $mapping, array $changes, WebhookEventContext $context): void
    {
        $this->values[$mapping->connection] = array_replace(
            $this->values[$mapping->connection] ?? ['first_name' => 'Ada', 'last_name' => 'Lovelace'],
            $changes,
        );
        $this->contexts[] = $context;
    }
}

function webhookBody(
    string $firstname = 'Grace',
    string $timestamp = '2026-10-19T12:00:00+00:00',
    ?string $eventId = null,
    int $remoteId = 100,
): string {
    $event = [
        'lead' => [
            'id' => $remoteId,
            'fields' => [
                'all' => [
                    'firstname' => $firstname,
                    'lastname' => 'Remote value must not win',
                    'email' => 'private@example.test',
                ],
            ],
            'doNotContact' => [['channel' => 'email', 'reason' => 1]],
        ],
        'timestamp' => $timestamp,
    ];
    if ($eventId !== null) {
        $event['eventId'] = $eventId;
    }

    return json_encode(['mautic.lead_post_save_update' => [$event]], JSON_THROW_ON_ERROR);
}

function webhookSignature(string $body, string $secret = 'main-webhook-secret'): string
{
    return base64_encode(hash_hmac('sha256', $body, $secret, true));
}

beforeEach(function () {
    config()->set('database.default', 'webhook-test');
    config()->set('database.connections.webhook-test', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    config()->set('mautic.identity.database_connection', 'webhook-test');
    config()->set('mautic.inbox.database_connection', 'webhook-test');
    config()->set('mautic.inbox.maximum_body_bytes', 10_000);
    config()->set('mautic.inbox.lease_seconds', 30);
    config()->set('mautic.connections.main.webhook.allowed_events', [
        'mautic.lead_post_save_update',
        'mautic.lead_post_delete',
        'mautic.lead_channel_subscription_changed',
    ]);
    config()->set('mautic.connections.secondary.webhook.allowed_events', [
        'mautic.lead_post_save_update',
    ]);
    DB::purge('webhook-test');

    $clock = new MutableWebhookClock;
    $projector = new TestInboundContactProjector;
    app()->instance(Clock::class, $clock);
    app()->instance(InboundContactProjector::class, $projector);
    app()->instance(WebhookSecretProvider::class, new class implements WebhookSecretProvider
    {
        public function secret(string $connection): string
        {
            return match ($connection) {
                'main' => 'main-webhook-secret',
                'secondary' => 'secondary-webhook-secret',
                default => throw new WebhookIngressException(WebhookIngressFailureKind::UnknownConnection),
            };
        }
    });
    $this->clock = $clock;
    $this->projector = $projector;

    $this->artisan('vendor:publish', ['--tag' => 'mautic-identity-migrations', '--force' => true])->assertSuccessful();
    $this->artisan('vendor:publish', ['--tag' => 'mautic-inbox-migrations', '--force' => true])->assertSuccessful();
    (require database_path('migrations/2026_10_19_010000_create_mautic_contact_mappings.php'))->up();
    (require database_path('migrations/2026_10_19_030000_create_mautic_webhook_inbox.php'))->up();

    $mappings = new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'webhook-test');
    $mappings->claim('main', new LocalContactReference('tenant:one:contact', '1'), 100);
    $mappings->claim('secondary', new LocalContactReference('tenant:two:contact', '1'), 100);
    Queue::fake();
});

it('rejects unauthenticated malformed and oversized requests before persistence', function () {
    $inbox = app(WebhookInbox::class);

    expect(fn () => $inbox->receive('main', '{bad json', null))
        ->toThrow(WebhookIngressException::class, 'signature is missing')
        ->and(fn () => $inbox->receive('main', '{bad json', 'invalid'))
        ->toThrow(WebhookIngressException::class, 'signature is invalid');

    $badJson = '{bad json';
    expect(fn () => $inbox->receive('main', $badJson, webhookSignature($badJson)))
        ->toThrow(WebhookIngressException::class, 'not valid JSON');

    config()->set('mautic.inbox.maximum_body_bytes', 1);
    expect(fn () => $inbox->receive('main', '{}', webhookSignature('{}')))
        ->toThrow(WebhookIngressException::class, 'exceeds the configured limit')
        ->and(DB::connection('webhook-test')->table('mautic_webhook_deliveries')->count())->toBe(0);
});

it('persists encrypted multi-type batches before asynchronous dispatch', function () {
    $body = json_encode([
        'mautic.lead_post_save_update' => [
            json_decode(webhookBody(), true, 64, JSON_THROW_ON_ERROR)['mautic.lead_post_save_update'][0],
            json_decode(webhookBody('Katherine', '2026-10-19T12:01:00+00:00', remoteId: 101), true, 64, JSON_THROW_ON_ERROR)['mautic.lead_post_save_update'][0],
        ],
        'custom.unknown' => [['value' => 'preserved']],
    ], JSON_THROW_ON_ERROR);

    $receipt = app(WebhookInbox::class)->receive('main', $body, webhookSignature($body));

    expect($receipt->eventCount)->toBe(3)
        ->and($receipt->dispatched)->toBeTrue()
        ->and(DB::connection('webhook-test')->table('mautic_webhook_deliveries')->count())->toBe(1)
        ->and(DB::connection('webhook-test')->table('mautic_webhook_events')->count())->toBe(3)
        ->and((string) DB::connection('webhook-test')->table('mautic_webhook_events')->value('payload'))
        ->not->toContain('private@example.test');
    Queue::assertPushed(ProcessWebhookInboxJob::class, 3);
});

it('reports durable persistence failure so Mautic can retry', function () {
    Schema::connection('webhook-test')->drop('mautic_webhook_events');
    $body = webhookBody();

    expect(fn () => app(WebhookInbox::class)->receive('main', $body, webhookSignature($body)))
        ->toThrow(WebhookIngressException::class, 'could not be persisted')
        ->and(DB::connection('webhook-test')->table('mautic_webhook_deliveries')->count())->toBe(0);
});

it('preserves repeated identical deliveries while change detection prevents echo writes', function () {
    $body = webhookBody();
    $first = app(WebhookInbox::class)->receive('main', $body, webhookSignature($body));
    $second = app(WebhookInbox::class)->receive('main', $body, webhookSignature($body));
    $store = app(DatabaseWebhookInboxStore::class);
    $processor = app(WebhookInboxProcessor::class);
    $firstId = $store->eventIdsForReceipt($first->receiptId)[0];
    $secondId = $store->eventIdsForReceipt($second->receiptId)[0];

    $processor->process($firstId);
    $processor->process($secondId);

    expect(DB::connection('webhook-test')->table('mautic_webhook_deliveries')->count())->toBe(2)
        ->and($store->status($firstId))->toBe(WebhookEventStatus::Processed)
        ->and($store->status($secondId))->toBe(WebhookEventStatus::NoChange)
        ->and($this->projector->values['main'])->toBe([
            'first_name' => 'Grace',
            'last_name' => 'Lovelace',
        ])
        ->and($this->projector->contexts)->toHaveCount(1);
});

it('deduplicates only explicit semantic event identities and rejects stale events', function () {
    $new = webhookBody('New', '2026-10-19T13:00:00+00:00', 'event-new');
    $duplicate = webhookBody('Ignored duplicate', '2026-10-19T13:01:00+00:00', 'event-new');
    $old = webhookBody('Old', '2026-10-19T12:00:00+00:00', 'event-old');
    $store = app(DatabaseWebhookInboxStore::class);
    $processor = app(WebhookInboxProcessor::class);

    $receipts = [];
    foreach ([$new, $duplicate, $old] as $body) {
        $receipts[] = app(WebhookInbox::class)->receive('main', $body, webhookSignature($body));
    }
    $ids = array_map(fn ($receipt): int => $store->eventIdsForReceipt($receipt->receiptId)[0], $receipts);
    foreach ($ids as $id) {
        $processor->process($id);
    }

    expect($store->status($ids[0]))->toBe(WebhookEventStatus::Processed)
        ->and($store->status($ids[1]))->toBe(WebhookEventStatus::Duplicate)
        ->and($store->status($ids[2]))->toBe(WebhookEventStatus::Stale)
        ->and($this->projector->values['main']['first_name'])->toBe('New');
});

it('keeps mappings connection scoped and never treats consent events as generic fields', function () {
    $secondaryBody = webhookBody('Secondary');
    $secondary = app(WebhookInbox::class)->receive(
        'secondary',
        $secondaryBody,
        webhookSignature($secondaryBody, 'secondary-webhook-secret'),
    );
    $consentBody = json_encode(['mautic.lead_channel_subscription_changed' => [[
        'contact' => ['id' => 100],
        'channel' => 'email',
        'old_status' => 'contactable',
        'new_status' => 'unsubscribed',
        'timestamp' => '2026-10-19T14:00:00+00:00',
    ]]], JSON_THROW_ON_ERROR);
    $consent = app(WebhookInbox::class)->receive('main', $consentBody, webhookSignature($consentBody));
    $store = app(DatabaseWebhookInboxStore::class);
    $processor = app(WebhookInboxProcessor::class);
    $secondaryId = $store->eventIdsForReceipt($secondary->receiptId)[0];
    $consentId = $store->eventIdsForReceipt($consent->receiptId)[0];

    $processor->process($secondaryId);
    $processor->process($consentId);

    expect($this->projector->values)->toHaveKey('secondary')
        ->not->toHaveKey('main')
        ->and($store->status($consentId))->toBe(WebhookEventStatus::Ignored);
});

it('recovers an event left processing by a crashed worker after its durable lease expires', function () {
    $body = webhookBody();
    $receipt = app(WebhookInbox::class)->receive('main', $body, webhookSignature($body));
    $store = app(DatabaseWebhookInboxStore::class);
    $eventId = $store->eventIdsForReceipt($receipt->receiptId)[0];

    expect($store->acquire($eventId, 'crashed-worker', 30))->not->toBeNull();
    $this->clock->timestamp = 1_031;

    app(WebhookInboxProcessor::class)->process($eventId);

    expect($store->status($eventId))->toBe(WebhookEventStatus::Processed)
        ->and($this->projector->values['main']['first_name'])->toBe('Grace');
});

it('defers concurrent work while another process owns the contact lease', function () {
    $body = webhookBody();
    $receipt = app(WebhookInbox::class)->receive('main', $body, webhookSignature($body));
    $store = app(DatabaseWebhookInboxStore::class);
    $eventId = $store->eventIdsForReceipt($receipt->receiptId)[0];

    expect($store->acquireContact('main', 100, 'other-process', 30))->toBeTrue();
    app(WebhookInboxProcessor::class)->process($eventId);
    expect($store->status($eventId))->toBe(WebhookEventStatus::Pending)
        ->and($this->projector->contexts)->toBeEmpty();

    $store->releaseContact('main', 100, 'other-process');
    app(WebhookInboxProcessor::class)->process($eventId);
    expect($store->status($eventId))->toBe(WebhookEventStatus::Processed);
});

it('updates mapping state for allowed deletes without exposing an automatic route', function () {
    $body = json_encode(['mautic.lead_post_delete' => [[
        'lead' => ['id' => 100],
        'timestamp' => '2026-10-19T15:00:00+00:00',
    ]]], JSON_THROW_ON_ERROR);
    $receipt = app(WebhookInbox::class)->receive('main', $body, webhookSignature($body));
    $store = app(DatabaseWebhookInboxStore::class);
    $eventId = $store->eventIdsForReceipt($receipt->receiptId)[0];

    app(WebhookInboxProcessor::class)->process($eventId);
    $mapping = app(ContactMappingRepository::class)
        ->findByRemoteId('main', 100);

    expect($mapping?->remoteState)->toBe(RemoteContactState::Deleted)
        ->and(app('router')->getRoutes()->count())->toBe(0);
});
