<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mautic\Auth\BasicAuth;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Contracts\ContactReader;
use Trianity\LaravelMautic\Contracts\ContactReaderProvider;
use Trianity\LaravelMautic\Contracts\ReconciliationSource;
use Trianity\LaravelMautic\Data\Contact;
use Trianity\LaravelMautic\Exceptions\MauticReadException;
use Trianity\LaravelMautic\Exceptions\OAuthReauthorizationRequired;
use Trianity\LaravelMautic\Exceptions\OAuthTokenPersistenceUncertain;
use Trianity\LaravelMautic\Exceptions\OAuthTokenRequestFailed;
use Trianity\LaravelMautic\Fakes\FakeContactReader;
use Trianity\LaravelMautic\Identity\ContactIdentityHasher;
use Trianity\LaravelMautic\Identity\DatabaseContactMappingRepository;
use Trianity\LaravelMautic\Identity\LocalContactReference;
use Trianity\LaravelMautic\Identity\RemoteContactState;
use Trianity\LaravelMautic\MauticConnection;
use Trianity\LaravelMautic\MauticServiceProvider;
use Trianity\LaravelMautic\OAuth\OAuthTokenFailureKind;
use Trianity\LaravelMautic\Read\ReadFailureKind;
use Trianity\LaravelMautic\Read\ReadOperation;
use Trianity\LaravelMautic\Reconciliation\ContactReconciler;
use Trianity\LaravelMautic\Reconciliation\DatabaseMauticDiagnostics;
use Trianity\LaravelMautic\Reconciliation\DatabaseReconciliationStore;
use Trianity\LaravelMautic\Reconciliation\MappingRecoveryService;
use Trianity\LaravelMautic\Reconciliation\OAuthCredentialHealth;
use Trianity\LaravelMautic\Reconciliation\OAuthTokenHealth;
use Trianity\LaravelMautic\Reconciliation\ReconciliationPage;
use Trianity\LaravelMautic\Reconciliation\ReconciliationStatus;

final class MutableReconciliationClock implements Clock
{
    public function __construct(public int $timestamp = 1_800_000_000) {}

    public function now(): int
    {
        return $this->timestamp;
    }
}

final class TestReconciliationSource implements ReconciliationSource
{
    /** @var list<int> */
    public array $remoteIds = [];

    public int $requests = 0;

    public bool $failPage = false;

    public ?Throwable $highWaterFailure = null;

    public ?Throwable $pageFailure = null;

    public ?ReconciliationPage $pageResult = null;

    public function highWatermark(string $connection): int
    {
        $this->requests++;
        if ($this->highWaterFailure !== null) {
            throw $this->highWaterFailure;
        }

        return $this->remoteIds === [] ? 0 : max($this->remoteIds);
    }

    public function page(string $connection, int $fromRemoteId, int $throughRemoteId, int $limit): ReconciliationPage
    {
        $this->requests++;
        if ($this->pageFailure !== null) {
            throw $this->pageFailure;
        }
        if ($this->failPage) {
            throw new MauticReadException(ReadFailureKind::RemoteServer, ReadOperation::SearchContacts, $connection, 503);
        }
        if ($this->pageResult !== null) {
            return $this->pageResult;
        }
        $ids = array_values(array_filter(
            $this->remoteIds,
            static fn (int $id): bool => $id > $fromRemoteId && $id <= $throughRemoteId,
        ));
        sort($ids, SORT_NUMERIC);

        return new ReconciliationPage(array_map(
            static fn (int $id): Contact => new Contact($id),
            array_slice($ids, 0, $limit),
        ), count($ids));
    }
}

final readonly class TestContactReaderProvider implements ContactReaderProvider
{
    public function __construct(private ContactReader $reader) {}

    public function forConnection(string $connection): ContactReader
    {
        return $this->reader;
    }
}

beforeEach(function () {
    config()->set('database.default', 'reconciliation-test');
    config()->set('database.connections.reconciliation-test', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    foreach (['identity', 'oauth', 'outbox', 'inbox', 'reconciliation'] as $section) {
        config()->set('mautic.'.$section.'.database_connection', 'reconciliation-test');
    }
    config()->set('mautic.reconciliation.page_size', 2);
    config()->set('mautic.reconciliation.minimum_page_interval_seconds', 10);
    config()->set('mautic.reconciliation.maximum_pages_per_run', 5);
    DB::purge('reconciliation-test');

    $clock = new MutableReconciliationClock;
    $source = new TestReconciliationSource;
    app()->instance(Clock::class, $clock);
    app()->instance(ReconciliationSource::class, $source);
    $this->clock = $clock;
    $this->source = $source;

    $this->artisan('vendor:publish', ['--tag' => 'mautic-identity-migrations', '--force' => true])->assertSuccessful();
    $this->artisan('vendor:publish', ['--tag' => 'mautic-reconciliation-migrations', '--force' => true])->assertSuccessful();
    (require database_path('migrations/2026_10_19_010000_create_mautic_contact_mappings.php'))->up();
    (require database_path('migrations/2026_10_19_040000_create_mautic_reconciliation.php'))->up();

    $this->mappings = new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'reconciliation-test');
});

it('publishes one final reversible reconciliation schema', function () {
    $published = MauticServiceProvider::pathsToPublish(
        MauticServiceProvider::class,
        'mautic-reconciliation-migrations',
    );
    $schema = Schema::connection('reconciliation-test');

    expect($published)->toHaveCount(1)
        ->and(array_key_first($published))->toEndWith('create_mautic_reconciliation.php.stub')
        ->and($schema->hasTable('mautic_reconciliation_checkpoints'))->toBeTrue()
        ->and($schema->hasTable('mautic_reconciliation_observations'))->toBeTrue()
        ->and($schema->hasTable('mautic_reconciliation_findings'))->toBeTrue()
        ->and($schema->hasTable('mautic_reconciliation_audits'))->toBeTrue();

    (require database_path('migrations/2026_10_19_040000_create_mautic_reconciliation.php'))->down();
    expect($schema->hasTable('mautic_reconciliation_audits'))->toBeFalse()
        ->and($schema->hasTable('mautic_reconciliation_findings'))->toBeFalse()
        ->and($schema->hasTable('mautic_reconciliation_observations'))->toBeFalse()
        ->and($schema->hasTable('mautic_reconciliation_checkpoints'))->toBeFalse();
});

it('resumes stable watermarked pages without duplicate observations and enforces request spacing', function () {
    $this->source->remoteIds = [10, 20, 30, 40];
    $this->mappings->claim('main', new LocalContactReference('tenant:test', '10'), 10);
    $inactive = $this->mappings->claim('main', new LocalContactReference('tenant:test', '30'), 30);
    $this->mappings->markRemoteState($inactive, RemoteContactState::Missing);
    $this->mappings->claim('main', new LocalContactReference('tenant:test', 'missing-remotely'), 99);
    $reconciler = app(ContactReconciler::class);
    $store = app(DatabaseReconciliationStore::class);

    $first = $reconciler->run('main');
    expect($first->status)->toBe(ReconciliationStatus::Running)
        ->and($first->highWaterRemoteId)->toBe(40)
        ->and($first->cursorRemoteId)->toBe(0)
        ->and($first->observedCount)->toBe(0)
        ->and($first->findingCount)->toBe(0)
        ->and($first->nextPageAt)->toBe(1_800_000_010)
        ->and($this->source->requests)->toBe(1);

    expect($reconciler->run('main'))->toEqual($first)
        ->and($this->source->requests)->toBe(1);

    $this->clock->timestamp += 10;
    $second = $reconciler->run('main');
    expect($second->status)->toBe(ReconciliationStatus::Running)
        ->and($second->cursorRemoteId)->toBe(20)
        ->and($second->observedCount)->toBe(2)
        ->and($second->findingCount)->toBe(1)
        ->and($second->nextPageAt)->toBe(1_800_000_020)
        ->and($this->source->requests)->toBe(2);

    $this->clock->timestamp += 10;
    $complete = $reconciler->run('main');
    expect($complete->status)->toBe(ReconciliationStatus::Completed)
        ->and($complete->cursorRemoteId)->toBe(40)
        ->and($complete->observedCount)->toBe(4)
        ->and($complete->findingCount)->toBe(3)
        ->and($store->observationCount($complete->scanId))->toBe(4)
        ->and($this->mappings->findByRemoteId('main', 99)?->remoteState)->toBe(RemoteContactState::Active);

    expect($reconciler->run('main'))->toEqual($complete);
    $restarted = $reconciler->run('main', true);
    expect($restarted->scanId)->not->toBe($complete->scanId)
        ->and($restarted->observedCount)->toBe(0)
        ->and($restarted->nextPageAt)->toBe(1_800_000_030);
});

it('retains a failed page checkpoint and never infers deletion from an incomplete scan', function () {
    $this->source->remoteIds = [10, 20];
    $mapping = $this->mappings->claim('main', new LocalContactReference('tenant:test', 'unseen'), 99);
    $this->source->failPage = true;

    $reconciler = app(ContactReconciler::class);
    $started = $reconciler->run('main');
    expect($started->requestCount)->toBe(1);
    $this->clock->timestamp += 10;
    $failed = $reconciler->run('main');
    expect($failed->status)->toBe(ReconciliationStatus::Failed)
        ->and($failed->cursorRemoteId)->toBe(0)
        ->and($failed->lastFailureKind)->toBe(ReadFailureKind::RemoteServer->value)
        ->and($failed->nextPageAt)->toBe(1_800_000_020)
        ->and(app(DatabaseReconciliationStore::class)->observationCount($failed->scanId))->toBe(0)
        ->and($this->mappings->find('main', $mapping->localReference)?->remoteState)->toBe(RemoteContactState::Active);

    $this->source->failPage = false;
    expect($reconciler->run('main'))->toEqual($failed);
    $this->clock->timestamp += 10;
    $resumed = $reconciler->run('main');
    expect($resumed->status)->toBe(ReconciliationStatus::Completed)
        ->and($resumed->observedCount)->toBe(2);
});

it('honors server retry delays for watermark page and transient OAuth throttling', function () {
    $throttled = new MauticReadException(
        ReadFailureKind::Throttled,
        ReadOperation::SearchContacts,
        'main',
        429,
        3_600,
    );
    $this->source->highWaterFailure = $throttled;
    $watermark = app(ContactReconciler::class)->run('main');
    expect($watermark->status)->toBe(ReconciliationStatus::Failed)
        ->and($watermark->nextPageAt)->toBe(1_800_003_600)
        ->and($watermark->requestCount)->toBe(1);
    $this->clock->timestamp += 3_600;
    $this->source->highWaterFailure = null;
    $this->source->remoteIds = [10];
    $watermark = app(ContactReconciler::class)->run('main');
    expect($watermark->requestCount)->toBe(2);

    $this->clock->timestamp += 10;
    $this->source->pageFailure = $throttled;
    $page = app(ContactReconciler::class)->run('main');
    expect($page->status)->toBe(ReconciliationStatus::Failed)
        ->and($page->nextPageAt)->toBe(1_800_007_210)
        ->and($page->requestCount)->toBe(3);

    $this->source->pageFailure = new OAuthTokenRequestFailed(
        'main',
        OAuthTokenFailureKind::Throttled,
        429,
        7_200,
    );
    $this->clock->timestamp = 1_800_007_210;
    $oauth = app(ContactReconciler::class)->run('main');
    expect($oauth->status)->toBe(ReconciliationStatus::Failed)
        ->and($oauth->nextPageAt)->toBe(1_800_014_410)
        ->and($oauth->requestCount)->toBe(4);
});

it('blocks reconciliation for OAuth outcomes that require operator resolution', function (Throwable $failure, string $kind) {
    $this->source->highWaterFailure = $failure;

    $blocked = app(ContactReconciler::class)->run('main');
    expect($blocked->status)->toBe(ReconciliationStatus::Blocked)
        ->and($blocked->lastFailureKind)->toBe($kind)
        ->and($blocked->nextPageAt)->toBeNull()
        ->and(app(ContactReconciler::class)->run('main'))->toEqual($blocked)
        ->and($this->source->requests)->toBe(1);
})->with([
    'uncertain persistence' => [new OAuthTokenPersistenceUncertain('main'), 'oauth_token_persistence_uncertain'],
    'reauthorization' => [new OAuthReauthorizationRequired('main'), 'oauth_reauthorization_required'],
]);

it('does not complete a short page while the response reports remaining contacts', function () {
    $this->source->remoteIds = [10, 20, 30];
    $reconciler = app(ContactReconciler::class);
    $reconciler->run('main');
    $this->clock->timestamp += 10;
    $this->source->pageResult = new ReconciliationPage([new Contact(10)], 3);

    $checkpoint = $reconciler->run('main');
    expect($checkpoint->status)->toBe(ReconciliationStatus::Running)
        ->and($checkpoint->cursorRemoteId)->toBe(10)
        ->and($checkpoint->observedCount)->toBe(1);
});

it('retains the committed cursor when a page cannot prove forward progress', function () {
    $this->source->remoteIds = [10];
    $reconciler = app(ContactReconciler::class);
    $reconciler->run('main');
    $this->clock->timestamp += 10;
    $this->source->pageResult = new ReconciliationPage([], 1);

    $checkpoint = $reconciler->run('main');
    expect($checkpoint->status)->toBe(ReconciliationStatus::Failed)
        ->and($checkpoint->lastFailureKind)->toBe(ReadFailureKind::UnexpectedResponse->value)
        ->and($checkpoint->cursorRemoteId)->toBe(0)
        ->and($checkpoint->observedCount)->toBe(0);
});

it('rejects a reserved result after an explicit scan restart', function () {
    $store = app(DatabaseReconciliationStore::class);
    $old = $store->reserve($store->begin('main', 10), 10, 30)
        ?? throw new RuntimeException('The initial request was not reserved.');
    $replacement = $store->begin('main', 10, true);

    expect($replacement->scanId)->not->toBe($old->scanId)
        ->and($replacement->generation)->toBeGreaterThan($old->generation)
        ->and(fn () => $store->setHighWatermark($old, 10))->toThrow(RuntimeException::class)
        ->and($store->get('main')?->scanId)->toBe($replacement->scanId);
});

it('dry-runs bounded missing mapping recovery and audits explicit application atomically', function () {
    $present = $this->mappings->claim('main', new LocalContactReference('tenant:test', 'present'), 10);
    $missing = $this->mappings->claim('main', new LocalContactReference('tenant:test', 'missing'), 20);
    $reader = FakeContactReader::make()->withContacts(new Contact(10));
    app()->instance(ContactReaderProvider::class, new TestContactReaderProvider($reader));
    $recovery = app(MappingRecoveryService::class);

    $dryRun = $recovery->run('main', limit: 100);
    expect($dryRun->dryRun)->toBeTrue()
        ->and($dryRun->checked)->toBe(2)
        ->and($dryRun->missingRemoteIds)->toBe([20])
        ->and($dryRun->applied)->toBe(0)
        ->and($this->mappings->find('main', $missing->localReference)?->remoteState)->toBe(RemoteContactState::Active)
        ->and(DB::connection('reconciliation-test')->table('mautic_reconciliation_audits')->count())->toBe(0);

    $applied = $recovery->run('main', limit: 100, applyMissing: true, actor: 'operator-1', reasonCode: 'confirmed_404');
    expect($applied->dryRun)->toBeFalse()
        ->and($applied->missingRemoteIds)->toBe([20])
        ->and($applied->applied)->toBe(1)
        ->and($this->mappings->find('main', $missing->localReference)?->remoteState)->toBe(RemoteContactState::Missing)
        ->and($this->mappings->find('main', $present->localReference)?->remoteState)->toBe(RemoteContactState::Active)
        ->and(DB::connection('reconciliation-test')->table('mautic_reconciliation_audits')->first())
        ->toMatchObject([
            'remote_id' => 20,
            'action' => 'mark_remote_missing',
            'actor' => 'operator-1',
            'reason_code' => 'confirmed_404',
        ]);
});

it('selects byte-exact mapping connections before applying limits', function () {
    $this->mappings->claim('main', new LocalContactReference('tenant:test', 'lower'), 20);
    $upperFirst = $this->mappings->claim('Main', new LocalContactReference('tenant:test', 'upper-20'), 20);
    $this->mappings->claim('Main', new LocalContactReference('tenant:test', 'upper-30'), 30);
    app()->instance(ContactReaderProvider::class, new TestContactReaderProvider(FakeContactReader::make()));
    $recovery = app(MappingRecoveryService::class);

    $dryRun = $recovery->run('Main', limit: 1);
    expect($dryRun->checked)->toBe(1)
        ->and($dryRun->missingRemoteIds)->toBe([20])
        ->and($dryRun->nextRemoteId)->toBe(20)
        ->and($dryRun->complete)->toBeFalse();

    $applied = $recovery->run(
        'Main',
        limit: 1,
        applyMissing: true,
        actor: 'operator-1',
        reasonCode: 'confirmed_404',
    );
    expect($applied->applied)->toBe(1)
        ->and($this->mappings->find('Main', $upperFirst->localReference)?->remoteState)->toBe(RemoteContactState::Missing)
        ->and($this->mappings->findByRemoteId('main', 20)?->remoteState)->toBe(RemoteContactState::Active)
        ->and(DB::connection('reconciliation-test')->table('mautic_reconciliation_audits')->count())->toBe(1);

    $next = $recovery->run('Main', afterRemoteId: 20, limit: 1);
    expect($next->missingRemoteIds)->toBe([30])
        ->and($next->complete)->toBeTrue();
});

it('reports only bounded operational diagnostics and token health', function () {
    $this->mappings->claim('main', new LocalContactReference('tenant:test', '10'), 10);
    $this->artisan('vendor:publish', ['--tag' => 'mautic-outbox-migrations', '--force' => true])->assertSuccessful();
    $this->artisan('vendor:publish', ['--tag' => 'mautic-inbox-migrations', '--force' => true])->assertSuccessful();
    $this->artisan('vendor:publish', ['--tag' => 'mautic-migrations', '--force' => true])->assertSuccessful();
    (require database_path('migrations/2026_10_19_000000_create_mautic_oauth_storage.php'))->up();
    (require database_path('migrations/2026_10_19_020000_create_mautic_contact_outbox.php'))->up();
    (require database_path('migrations/2026_10_19_030000_create_mautic_webhook_inbox.php'))->up();
    $database = DB::connection('reconciliation-test');
    $now = date('Y-m-d H:i:s', $this->clock->now());
    $database->table('mautic_oauth_tokens')->insert([
        'connection' => 'main',
        'token_payload' => 'encrypted-value-not-read-by-diagnostics',
        'expires_at' => $this->clock->now() - 1,
        'generation' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $database->table('mautic_outbox_commands')->insert([
        'operation_id' => 'ambiguous-operation',
        'connection' => 'main',
        'connection_hash' => ContactIdentityHasher::connection('main'),
        'local_scope' => 'private-scope',
        'local_key' => 'private-key',
        'aggregate_hash' => str_repeat('a', 64),
        'revision' => 1,
        'operation' => 'update',
        'source' => 'test',
        'payload' => '{"private":"value"}',
        'status' => 'ambiguous',
        'delivery_stage' => 'operation_in_flight',
        'attempts' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $deliveryId = $database->table('mautic_webhook_deliveries')->insertGetId([
        'receipt_id' => str_repeat('b', 64),
        'connection' => 'main',
        'connection_hash' => ContactIdentityHasher::connection('main'),
        'body_hash' => str_repeat('c', 64),
        'event_count' => 1,
        'received_at' => $this->clock->now(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $database->table('mautic_webhook_events')->insert([
        'delivery_id' => $deliveryId,
        'ordinal' => 0,
        'event_type' => 'test',
        'payload' => 'encrypted-private-payload',
        'payload_hash' => str_repeat('d', 64),
        'status' => 'failed',
        'attempts' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    config()->set('mautic.connections.main.auth', 'oauth2');

    $diagnostics = app(DatabaseMauticDiagnostics::class)->forConnection('main');
    $encoded = json_encode($diagnostics->toArray(), JSON_THROW_ON_ERROR);

    expect($diagnostics->mappingStates)->toBe(['active' => 1])
        ->and($diagnostics->tokenHealth)->toBe(OAuthTokenHealth::Expired)
        ->and($diagnostics->credentialHealth)->toBe(OAuthCredentialHealth::Unavailable)
        ->and($diagnostics->outboxStates)->toBe(['ambiguous' => 1])
        ->and($diagnostics->inboxStates)->toBe(['failed' => 1])
        ->and($encoded)->not->toContain('local_scope', 'local_key', 'payload', 'token_payload', 'secret');

    putenv('MAUTIC_DIAGNOSTIC_SECRET=runtime-only-value');
    config()->set('mautic.connections.main.oauth', [
        'client_id' => 'client-id',
        'client_secret_env' => 'MAUTIC_DIAGNOSTIC_SECRET',
    ]);
    expect(app(DatabaseMauticDiagnostics::class)->forConnection('main')->credentialHealth)
        ->toBe(OAuthCredentialHealth::Available);
    putenv('MAUTIC_DIAGNOSTIC_SECRET');
});

it('uses the real SDK boundary with an ID watermark and keyset ordering', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'total' => 3,
            'contacts' => [['id' => 40]],
        ], JSON_THROW_ON_ERROR)),
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'total' => 2,
            'contacts' => [['id' => 30], ['id' => 40]],
        ], JSON_THROW_ON_ERROR)),
    ]));
    $stack->push(Middleware::history($history));
    $auth = new BasicAuth(new Client(['handler' => $stack]));
    $auth->setup('integration', 'secret');
    $source = (new MauticConnection('main', 'https://mautic.example.test/api/', $auth))
        ->reconciliationReads();

    expect($source->highWatermark())->toBe(40)
        ->and(array_map(
            static fn (Contact $contact): int => $contact->remoteId,
            $source->page(20, 40, 2)->contacts,
        ))->toBe([30, 40]);

    parse_str($history[0]['request']->getUri()->getQuery(), $highWaterQuery);
    parse_str($history[1]['request']->getUri()->getQuery(), $pageQuery);
    expect($highWaterQuery)->toMatchArray([
        'limit' => '1',
        'orderBy' => 'id',
        'orderByDir' => 'DESC',
    ])->and($pageQuery)->toMatchArray([
        'limit' => '2',
        'where' => [
            ['col' => 'id', 'expr' => 'gt', 'val' => '20'],
            ['col' => 'id', 'expr' => 'lte', 'val' => '40'],
        ],
        'order' => [['col' => 'id', 'dir' => 'asc']],
    ]);
});

it('rejects contradictory watermark totals and stale response metadata after transport loss', function () {
    $contradictory = new BasicAuth(new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], '{"total":1,"contacts":[]}'),
    ]))]));
    $contradictory->setup('integration', 'secret');
    $source = (new MauticConnection('main', 'https://mautic.example.test/api/', $contradictory))->reconciliationReads();
    expect(fn () => $source->highWatermark())->toThrow(fn (MauticReadException $exception) => $exception->kind === ReadFailureKind::UnexpectedResponse);

    $auth = new BasicAuth(new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json', 'Retry-After' => '999'], '{"total":1,"contacts":[{"id":10}]}'),
        new ConnectException('Connection failed', new Request('GET', 'https://mautic.example.test/api/contacts')),
    ]))]));
    $auth->setup('integration', 'secret');
    $source = (new MauticConnection('main', 'https://mautic.example.test/api/', $auth))->reconciliationReads();
    expect($source->highWatermark())->toBe(10)
        ->and(fn () => $source->page(0, 10, 10))->toThrow(fn (MauticReadException $exception) => $exception->kind === ReadFailureKind::Transport
            && $exception->retryAfterSeconds === null);
});

it('preserves current numeric Retry-After metadata for reconciliation requests', function (string $request, string $header, ?int $expected) {
    $responses = [];
    if ($request === 'page') {
        $responses[] = new Response(200, ['Content-Type' => 'application/json'], '{"total":1,"contacts":[{"id":10}]}');
    }
    $responses[] = new Response(
        429,
        ['Content-Type' => 'application/json', 'Retry-After' => $header],
        '{"errors":[{"code":429,"message":"slow down"}]}',
    );
    $auth = new BasicAuth(new Client(['handler' => HandlerStack::create(new MockHandler($responses))]));
    $auth->setup('integration', 'secret');
    $source = (new MauticConnection('main', 'https://mautic.example.test/api/', $auth))->reconciliationReads();
    if ($request === 'page') {
        expect($source->highWatermark())->toBe(10);
    }

    $requestCall = $request === 'watermark'
        ? fn (): int => $source->highWatermark()
        : fn (): ReconciliationPage => $source->page(0, 10, 10);
    expect($requestCall)->toThrow(fn (MauticReadException $exception) => $exception->kind === ReadFailureKind::Throttled
        && $exception->retryAfterSeconds === $expected);
})->with([
    'watermark numeric' => ['watermark', '3600', 3_600],
    'page numeric' => ['page', '3600', 3_600],
    'watermark malformed' => ['watermark', 'later', null],
    'page malformed' => ['page', 'later', null],
]);

it('preserves SDK range totals for short final and empty reconciliation pages', function () {
    $auth = new BasicAuth(new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], '{"total":3,"contacts":[{"id":10}]}'),
        new Response(200, ['Content-Type' => 'application/json'], '{"total":1,"contacts":[{"id":30}]}'),
        new Response(200, ['Content-Type' => 'application/json'], '{"total":0,"contacts":[]}'),
    ]))]));
    $auth->setup('integration', 'secret');
    $source = (new MauticConnection('main', 'https://mautic.example.test/api/', $auth))->reconciliationReads();

    $short = $source->page(0, 40, 2);
    $final = $source->page(20, 40, 2);
    $empty = $source->page(40, 40, 2);
    expect($short->remainingCount)->toBe(3)
        ->and(array_column($short->contacts, 'remoteId'))->toBe([10])
        ->and($final->remainingCount)->toBe(1)
        ->and(array_column($final->contacts, 'remoteId'))->toBe([30])
        ->and($empty->remainingCount)->toBe(0)
        ->and($empty->contacts)->toBe([]);
});

it('registers read-only diagnostics and dry-run-first bounded recovery commands', function () {
    $this->source->remoteIds = [];
    app()->instance(ContactReaderProvider::class, new TestContactReaderProvider(FakeContactReader::make()));

    $this->artisan('mautic:reconcile', ['connection' => 'main'])
        ->expectsOutputToContain('"status":"completed"')
        ->assertSuccessful();
    $this->artisan('mautic:diagnose', ['connection' => 'main'])
        ->expectsOutputToContain('"connection":"main"')
        ->assertSuccessful();
    $this->artisan('mautic:reconcile-mappings', ['connection' => 'main', '--limit' => 1])
        ->expectsOutputToContain('"dry_run":true')
        ->assertSuccessful();
});
