<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Contracts\ContactMappingRepository;
use Trianity\LaravelMautic\Contracts\ContactSyncTransport;
use Trianity\LaravelMautic\Exceptions\InvalidConfiguration;
use Trianity\LaravelMautic\Exceptions\MauticReadException;
use Trianity\LaravelMautic\Exceptions\MauticWriteException;
use Trianity\LaravelMautic\Exceptions\OAuthReauthorizationRequired;
use Trianity\LaravelMautic\Exceptions\OAuthTokenPersistenceUncertain;
use Trianity\LaravelMautic\Exceptions\OAuthTokenRequestFailed;
use Trianity\LaravelMautic\Exceptions\OutboxConflict;
use Trianity\LaravelMautic\Identity\DatabaseContactMappingRepository;
use Trianity\LaravelMautic\Identity\LocalContactReference;
use Trianity\LaravelMautic\Jobs\ProcessContactOutboxJob;
use Trianity\LaravelMautic\OAuth\OAuthTokenFailureKind;
use Trianity\LaravelMautic\Outbox\ContactOutbox;
use Trianity\LaravelMautic\Outbox\ContactSyncCommand;
use Trianity\LaravelMautic\Outbox\DatabaseOutboxStore;
use Trianity\LaravelMautic\Outbox\OutboxDispatcher;
use Trianity\LaravelMautic\Outbox\OutboxOperator;
use Trianity\LaravelMautic\Outbox\OutboxPoller;
use Trianity\LaravelMautic\Outbox\OutboxProcessor;
use Trianity\LaravelMautic\Outbox\OutboxStatus;
use Trianity\LaravelMautic\Read\ReadFailureKind;
use Trianity\LaravelMautic\Read\ReadOperation;
use Trianity\LaravelMautic\Write\ContactFields;
use Trianity\LaravelMautic\Write\FailureKind;
use Trianity\LaravelMautic\Write\MutationOperation;

function fixedOutboxClock(int $now = 1_000): Clock
{
    return new class($now) implements Clock
    {
        public function __construct(public int $timestamp) {}

        public function now(): int
        {
            return $this->timestamp;
        }
    };
}

function updateOutboxCommand(string $operationId, int $revision, string $connection = 'main'): ContactSyncCommand
{
    return ContactSyncCommand::update(
        $operationId,
        $connection,
        new LocalContactReference('tenant:acme:crm-contact', 'contact-01'),
        $revision,
        ContactFields::fromArray(['firstname' => 'Revision '.$revision], ['firstname']),
    );
}

beforeEach(function () {
    config()->set('database.default', 'outbox-test');
    config()->set('database.connections.outbox-test', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    config()->set('mautic.identity.database_connection', 'outbox-test');
    config()->set('mautic.outbox.database_connection', 'outbox-test');
    DB::purge('outbox-test');

    $this->artisan('vendor:publish', ['--tag' => 'mautic-identity-migrations', '--force' => true])
        ->assertSuccessful();
    $this->artisan('vendor:publish', ['--tag' => 'mautic-outbox-migrations', '--force' => true])
        ->assertSuccessful();
    (require database_path('migrations/2026_10_19_010000_create_mautic_contact_mappings.php'))->up();
    (require database_path('migrations/2026_10_19_020000_create_mautic_contact_outbox.php'))->up();
});

it('records in the host transaction and dispatches only after commit', function () {
    Queue::fake();
    $clock = fixedOutboxClock();
    $store = new DatabaseOutboxStore(DB::getFacadeRoot(), $clock, 'outbox-test');
    $outbox = new ContactOutbox($store, new OutboxDispatcher(Queue::getFacadeRoot(), $store));

    try {
        DB::connection('outbox-test')->transaction(function () use ($outbox): void {
            $outbox->record(updateOutboxCommand('rolled-back', 1));
            throw new RuntimeException('host rollback');
        });
    } catch (RuntimeException) {
        // Expected host rollback.
    }

    expect(DB::connection('outbox-test')->table('mautic_outbox_commands')->count())->toBe(0);
    Queue::assertNothingPushed();

    DB::connection('outbox-test')->transaction(
        fn () => $outbox->record(updateOutboxCommand('committed', 1)),
    );

    expect(DB::connection('outbox-test')->table('mautic_outbox_commands')->count())->toBe(1);
    Queue::assertPushed(ProcessContactOutboxJob::class, fn ($job) => $job->operationId === 'committed');
});

it('requires the shared processing lease to exceed the declared complete-operation budget', function () {
    config()->set('mautic.outbox.lease_seconds', 10);
    config()->set('mautic.outbox.operation_budget_seconds', 10);
    config()->set('mautic.connections.main.timeout', 3);
    app()->forgetInstance(OutboxProcessor::class);

    expect(fn () => app(OutboxProcessor::class))->toThrow(InvalidConfiguration::class);
});

it('retains a committed command across dispatch failure and lets the poller recover it', function () {
    $clock = fixedOutboxClock();
    $store = new DatabaseOutboxStore(DB::getFacadeRoot(), $clock, 'outbox-test');
    $failedQueue = Mockery::mock(QueueContract::class);
    $failedQueue->shouldReceive('push')->once()->andThrow(new RuntimeException('queue unavailable'));
    $outbox = new ContactOutbox($store, new OutboxDispatcher($failedQueue, $store));

    $outbox->record(updateOutboxCommand('dispatch-gap', 1));

    expect($store->get('dispatch-gap')?->status)->toBe(OutboxStatus::Pending)
        ->and($store->get('dispatch-gap')?->dispatchedAt)->toBeNull();

    Queue::fake();
    $poller = new OutboxPoller($store, new OutboxDispatcher(Queue::getFacadeRoot(), $store));
    expect($poller->recover(10))->toBe(1);
    Queue::assertPushed(ProcessContactOutboxJob::class, fn ($job) => $job->operationId === 'dispatch-gap');
});

it('makes exact recording idempotent and rejects conflicting operation identity or revision reuse', function () {
    Queue::fake();
    $clock = fixedOutboxClock();
    $store = new DatabaseOutboxStore(DB::getFacadeRoot(), $clock, 'outbox-test');
    $outbox = new ContactOutbox($store, new OutboxDispatcher(Queue::getFacadeRoot(), $store));
    $command = updateOutboxCommand('stable-operation', 1);

    expect($outbox->record($command)->operationId)->toBe($outbox->record($command)->operationId)
        ->and(DB::connection('outbox-test')->table('mautic_outbox_commands')->count())->toBe(1)
        ->and(fn () => $outbox->record(updateOutboxCommand('stable-operation', 2)))
        ->toThrow(OutboxConflict::class)
        ->and(fn () => $outbox->record(updateOutboxCommand('different-operation', 1)))
        ->toThrow(OutboxConflict::class);
});

it('delivers partial revisions sequentially despite out-of-order jobs and isolates connections', function () {
    Queue::fake();
    $clock = fixedOutboxClock();
    $store = new DatabaseOutboxStore(DB::getFacadeRoot(), $clock, 'outbox-test');
    $outbox = new ContactOutbox($store, new OutboxDispatcher(Queue::getFacadeRoot(), $store));
    $mappings = new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'outbox-test');
    $reference = new LocalContactReference('tenant:acme:crm-contact', 'contact-01');
    $mappings->claim('main', $reference, 42);
    $mappings->claim('secondary', $reference, 84);
    $transport = new class implements ContactSyncTransport
    {
        /** @var list<array{string, int, array<string, mixed>}> */
        public array $updates = [];

        public function create(string $connection, ContactFields $fields): int
        {
            throw new LogicException('Create was not expected.');
        }

        public function update(string $connection, int $remoteId, ContactFields $fields): void
        {
            $this->updates[] = [$connection, $remoteId, $fields->toPayload()];
        }
    };
    $processor = new OutboxProcessor($store, $transport, $mappings, $clock, leaseSeconds: 30, maxAttempts: 5);

    $outbox->record(updateOutboxCommand('main-v1', 1));
    $outbox->record(updateOutboxCommand('main-v2', 2));
    $outbox->record(updateOutboxCommand('secondary-v1', 1, 'secondary'));
    $processor->process('main-v2');
    expect($transport->updates)->toBe([]);
    $processor->process('main-v1');
    $processor->process('main-v2');
    $processor->process('main-v2');
    $processor->process('secondary-v1');

    expect($store->get('main-v1')?->status)->toBe(OutboxStatus::Completed)
        ->and($store->get('main-v2')?->status)->toBe(OutboxStatus::Completed)
        ->and($store->get('secondary-v1')?->status)->toBe(OutboxStatus::Completed)
        ->and($transport->updates)->toBe([
            ['main', 42, ['firstname' => 'Revision 1']],
            ['main', 42, ['firstname' => 'Revision 2']],
            ['secondary', 84, ['firstname' => 'Revision 1']],
        ]);
});

it('preserves a create prerequisite and blocks later revisions behind unresolved work', function () {
    Queue::fake();
    $clock = fixedOutboxClock();
    $store = new DatabaseOutboxStore(DB::getFacadeRoot(), $clock, 'outbox-test');
    $outbox = new ContactOutbox($store, new OutboxDispatcher(Queue::getFacadeRoot(), $store));
    $mappings = new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'outbox-test');
    $reference = new LocalContactReference('tenant:acme', 'ordered-create');
    $transport = new class implements ContactSyncTransport
    {
        /** @var list<string> */
        public array $calls = [];

        public function create(string $connection, ContactFields $fields): int
        {
            $this->calls[] = 'create';

            return 77;
        }

        public function update(string $connection, int $remoteId, ContactFields $fields): void
        {
            $this->calls[] = 'update:'.implode(',', array_keys($fields->toPayload()));
        }
    };
    $outbox->record(ContactSyncCommand::create(
        'ordered-create-v1',
        'main',
        $reference,
        1,
        ContactFields::fromArray(['firstname' => 'Ada'], ['firstname']),
    ));
    $outbox->record(ContactSyncCommand::update(
        'ordered-create-v2',
        'main',
        $reference,
        2,
        ContactFields::fromArray(['phone' => '+361234'], ['phone']),
    ));
    $processor = new OutboxProcessor($store, $transport, $mappings, $clock);

    $processor->process('ordered-create-v2');
    expect($transport->calls)->toBe([]);
    $processor->process('ordered-create-v1');
    $processor->process('ordered-create-v2');

    expect($transport->calls)->toBe(['create', 'update:phone'])
        ->and($mappings->find('main', $reference)?->remoteId)->toBe(77);

    $outbox->record(ContactSyncCommand::update(
        'ordered-create-v3',
        'main',
        $reference,
        3,
        ContactFields::fromArray(['company' => 'Example'], ['company']),
    ));
    DB::connection('outbox-test')->table('mautic_outbox_commands')
        ->where('operation_id', 'ordered-create-v2')
        ->update(['status' => 'failed']);
    $processor->process('ordered-create-v3');

    expect($store->get('ordered-create-v3')?->status)->toBe(OutboxStatus::Pending)
        ->and($transport->calls)->toBe(['create', 'update:phone']);
});

it('turns an expired create into an inspectable ambiguity and reconciles it audibly', function () {
    Queue::fake();
    $clock = fixedOutboxClock();
    $store = new DatabaseOutboxStore(DB::getFacadeRoot(), $clock, 'outbox-test');
    $outbox = new ContactOutbox($store, new OutboxDispatcher(Queue::getFacadeRoot(), $store));
    $mappings = new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'outbox-test');
    $transport = Mockery::mock(ContactSyncTransport::class);
    $transport->shouldNotReceive('create', 'update');
    $command = ContactSyncCommand::create(
        'create-crashed',
        'main',
        new LocalContactReference('tenant:acme:crm-contact', 'new-contact'),
        1,
        ContactFields::fromArray(['email' => 'new@example.test'], ['email']),
    );
    $outbox->record($command);
    DB::connection('outbox-test')->table('mautic_outbox_commands')->where('operation_id', 'create-crashed')->update([
        'status' => 'processing',
        'attempts' => 1,
        'lease_owner' => 'dead-worker',
        'lease_expires_at' => 999,
    ]);
    $processor = new OutboxProcessor($store, $transport, $mappings, $clock, leaseSeconds: 30, maxAttempts: 5);

    $processor->process('create-crashed');

    expect($store->get('create-crashed')?->status)->toBe(OutboxStatus::Ambiguous)
        ->and($store->get('create-crashed')?->lastErrorCode)->toBe('operation_worker_lost');

    $operator = new OutboxOperator($store, $mappings);
    $operator->reconcileCreated('create-crashed', 77, 'operator-17', 'verified_exact_remote');

    expect($store->get('create-crashed')?->status)->toBe(OutboxStatus::Completed)
        ->and($mappings->find('main', $command->localReference)?->remoteId)->toBe(77)
        ->and(DB::connection('outbox-test')->table('mautic_outbox_audits')->where('operation_id', 'create-crashed')->value('actor'))->toBe('operator-17');
});

it('creates once, persists identity and retains validation failures for audited operator retry', function () {
    Queue::fake();
    $clock = fixedOutboxClock();
    $store = new DatabaseOutboxStore(DB::getFacadeRoot(), $clock, 'outbox-test');
    $outbox = new ContactOutbox($store, new OutboxDispatcher(Queue::getFacadeRoot(), $store));
    $mappings = new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'outbox-test');
    $transport = new class implements ContactSyncTransport
    {
        public int $creates = 0;

        public function create(string $connection, ContactFields $fields): int
        {
            $this->creates++;

            return 77;
        }

        public function update(string $connection, int $remoteId, ContactFields $fields): void
        {
            throw new MauticWriteException(FailureKind::Validation, MutationOperation::UpdateContact, $connection, 422);
        }
    };
    $reference = new LocalContactReference('tenant:acme', 'new-contact');
    $outbox->record(ContactSyncCommand::create(
        'create-once',
        'main',
        $reference,
        1,
        ContactFields::fromArray(['email' => 'person@example.test'], ['email']),
    ));
    $processor = new OutboxProcessor($store, $transport, $mappings, $clock, leaseSeconds: 30, maxAttempts: 5);
    $processor->process('create-once');
    $processor->process('create-once');

    expect($transport->creates)->toBe(1)
        ->and($store->get('create-once')?->status)->toBe(OutboxStatus::Completed)
        ->and($mappings->find('main', $reference)?->remoteId)->toBe(77);

    $outbox->record(ContactSyncCommand::update(
        'invalid-update',
        'main',
        $reference,
        2,
        ContactFields::fromArray(['firstname' => 'Ada'], ['firstname']),
    ));
    $processor->process('invalid-update');
    expect($store->get('invalid-update')?->status)->toBe(OutboxStatus::Failed)
        ->and($store->get('invalid-update')?->lastFailureKind)->toBe(FailureKind::Validation->value);

    (new OutboxOperator($store, $mappings))->retryFailed('invalid-update', 'operator-18', 'fixed_remote_field');
    expect($store->get('invalid-update')?->status)->toBe(OutboxStatus::Pending)
        ->and(DB::connection('outbox-test')->table('mautic_outbox_audits')->where('operation_id', 'invalid-update')->value('action'))
        ->toBe('retry_failed');
});

it('uses bounded retry policy and stores only sanitized failure diagnostics', function () {
    Queue::fake();
    $clock = fixedOutboxClock();
    $store = new DatabaseOutboxStore(DB::getFacadeRoot(), $clock, 'outbox-test');
    $outbox = new ContactOutbox($store, new OutboxDispatcher(Queue::getFacadeRoot(), $store));
    $mappings = new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'outbox-test');
    $mappings->claim('main', new LocalContactReference('tenant:acme:crm-contact', 'contact-01'), 42);
    $transport = new class implements ContactSyncTransport
    {
        public function create(string $connection, ContactFields $fields): int
        {
            throw new LogicException('Create was not expected.');
        }

        public function update(string $connection, int $remoteId, ContactFields $fields): void
        {
            throw new MauticWriteException(
                FailureKind::Throttled,
                MutationOperation::UpdateContact,
                $connection,
                429,
                retryAfterSeconds: 17,
            );
        }
    };
    $outbox->record(updateOutboxCommand('retry-after', 1));
    $processor = new OutboxProcessor($store, $transport, $mappings, $clock, leaseSeconds: 30, maxAttempts: 5);

    $processor->process('retry-after');
    $record = $store->get('retry-after');

    expect($record?->status)->toBe(OutboxStatus::RetryWait)
        ->and($record?->nextAttemptAt)->toBe(1_017)
        ->and($record?->lastFailureKind)->toBe(FailureKind::Throttled->value)
        ->and($record?->lastHttpStatus)->toBe(429)
        ->and($record?->lastErrorCode)->toBe('remote_throttled')
        ->and(json_encode(DB::connection('outbox-test')->table('mautic_outbox_commands')->first()))
        ->not->toContain('queue unavailable', 'new@example.test');
});

it('treats Retry-After as a server-directed minimum beyond the local backoff cap', function () {
    Queue::fake();
    $clock = fixedOutboxClock();
    $store = new DatabaseOutboxStore(DB::getFacadeRoot(), $clock, 'outbox-test');
    $outbox = new ContactOutbox($store, new OutboxDispatcher(Queue::getFacadeRoot(), $store));
    $mappings = new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'outbox-test');
    $mappings->claim('main', new LocalContactReference('tenant:acme:crm-contact', 'contact-01'), 42);
    $transport = new class implements ContactSyncTransport
    {
        public function create(string $connection, ContactFields $fields): int
        {
            throw new LogicException('Create was not expected.');
        }

        public function update(string $connection, int $remoteId, ContactFields $fields): void
        {
            throw new MauticWriteException(
                FailureKind::Throttled,
                MutationOperation::UpdateContact,
                $connection,
                429,
                retryAfterSeconds: 3_600,
            );
        }
    };
    $outbox->record(updateOutboxCommand('long-retry-after', 1));

    (new OutboxProcessor($store, $transport, $mappings, $clock, maximumDelaySeconds: 300))
        ->process('long-retry-after');

    expect($store->get('long-retry-after')?->nextAttemptAt)->toBe(4_600);
});

it('caps locally calculated exponential backoff when the server gives no delay', function () {
    Queue::fake();
    $clock = fixedOutboxClock();
    $store = new DatabaseOutboxStore(DB::getFacadeRoot(), $clock, 'outbox-test');
    $outbox = new ContactOutbox($store, new OutboxDispatcher(Queue::getFacadeRoot(), $store));
    $mappings = new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'outbox-test');
    $mappings->claim('main', new LocalContactReference('tenant:acme:crm-contact', 'contact-01'), 42);
    $transport = new class implements ContactSyncTransport
    {
        public function create(string $connection, ContactFields $fields): int
        {
            throw new LogicException('Create was not expected.');
        }

        public function update(string $connection, int $remoteId, ContactFields $fields): void
        {
            throw new MauticWriteException(FailureKind::RemoteServer, MutationOperation::UpdateContact, $connection, 503);
        }
    };
    $outbox->record(updateOutboxCommand('local-backoff', 1));

    (new OutboxProcessor($store, $transport, $mappings, $clock, maximumDelaySeconds: 3))
        ->process('local-backoff');

    expect($store->get('local-backoff')?->nextAttemptAt)->toBe(1_003);
});

it('retains uncertain create results and remote identity evidence for reconciliation', function () {
    Queue::fake();
    $clock = fixedOutboxClock();
    $store = new DatabaseOutboxStore(DB::getFacadeRoot(), $clock, 'outbox-test');
    $outbox = new ContactOutbox($store, new OutboxDispatcher(Queue::getFacadeRoot(), $store));
    $reference = new LocalContactReference('tenant:acme', 'uncertain-create');
    $outbox->record(ContactSyncCommand::create(
        'create-server-error',
        'main',
        $reference,
        1,
        ContactFields::fromArray(['email' => 'one@example.test'], ['email']),
    ));
    $serverError = new class implements ContactSyncTransport
    {
        public function create(string $connection, ContactFields $fields): int
        {
            throw new MauticWriteException(FailureKind::RemoteServer, MutationOperation::CreateContact, $connection, 503);
        }

        public function update(string $connection, int $remoteId, ContactFields $fields): void {}
    };
    (new OutboxProcessor(
        $store,
        $serverError,
        new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'outbox-test'),
        $clock,
    ))->process('create-server-error');

    expect($store->get('create-server-error')?->status)->toBe(OutboxStatus::Ambiguous)
        ->and(fn () => (new OutboxOperator(
            $store,
            new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'outbox-test'),
        ))->retryFailed('create-server-error', 'operator', 'try_again'))
        ->toThrow(RuntimeException::class);
});

it('retains the returned remote ID when create mapping persistence fails', function () {
    Queue::fake();
    $clock = fixedOutboxClock();
    $store = new DatabaseOutboxStore(DB::getFacadeRoot(), $clock, 'outbox-test');
    $outbox = new ContactOutbox($store, new OutboxDispatcher(Queue::getFacadeRoot(), $store));
    $reference = new LocalContactReference('tenant:acme', 'mapping-failure');
    $mappings = Mockery::mock(ContactMappingRepository::class);
    $sameReference = fn (LocalContactReference $value): bool => $value->scope === $reference->scope && $value->key === $reference->key;
    $mappings->shouldReceive('find')->once()->with('main', Mockery::on($sameReference))->andReturnNull();
    $mappings->shouldReceive('claim')->once()->with('main', Mockery::on($sameReference), 88)
        ->andThrow(new RuntimeException('database unavailable'));
    $transport = new class implements ContactSyncTransport
    {
        public function create(string $connection, ContactFields $fields): int
        {
            return 88;
        }

        public function update(string $connection, int $remoteId, ContactFields $fields): void {}
    };
    $outbox->record(ContactSyncCommand::create(
        'create-mapping-failure',
        'main',
        $reference,
        1,
        ContactFields::fromArray(['email' => 'mapping@example.test'], ['email']),
    ));

    (new OutboxProcessor($store, $transport, $mappings, $clock))->process('create-mapping-failure');

    expect($store->get('create-mapping-failure')?->status)->toBe(OutboxStatus::Ambiguous)
        ->and($store->get('create-mapping-failure')?->remoteId)->toBe(88)
        ->and($store->get('create-mapping-failure')?->lastErrorCode)->toBe('create_mapping_persistence_uncertain');
});

it('blocks a connection for OAuth state requiring external reconciliation', function (Throwable $failure, string $reason) {
    Queue::fake();
    $clock = fixedOutboxClock();
    $store = new DatabaseOutboxStore(DB::getFacadeRoot(), $clock, 'outbox-test');
    $outbox = new ContactOutbox($store, new OutboxDispatcher(Queue::getFacadeRoot(), $store));
    $mappings = new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'outbox-test');
    $reference = new LocalContactReference('tenant:acme:crm-contact', 'contact-01');
    $otherReference = new LocalContactReference('tenant:acme:crm-contact', 'contact-02');
    $mappings->claim('main', $reference, 42);
    $mappings->claim('main', $otherReference, 43);
    $transport = new class($failure) implements ContactSyncTransport
    {
        public int $calls = 0;

        public function __construct(private Throwable $failure) {}

        public function create(string $connection, ContactFields $fields): int
        {
            throw new LogicException('Create was not expected.');
        }

        public function update(string $connection, int $remoteId, ContactFields $fields): void
        {
            $this->calls++;
            throw $this->failure;
        }
    };
    $outbox->record(updateOutboxCommand('oauth-block-v1', 1));
    $processor = new OutboxProcessor($store, $transport, $mappings, $clock);
    $processor->process('oauth-block-v1');
    $outbox->record(ContactSyncCommand::update(
        'oauth-block-v2',
        'main',
        $otherReference,
        1,
        ContactFields::fromArray(['firstname' => 'Other'], ['firstname']),
    ));
    $processor->process('oauth-block-v2');

    expect($store->get('oauth-block-v1')?->status->value)->toBe('blocked')
        ->and($store->get('oauth-block-v1')?->lastErrorCode)->toBe($reason)
        ->and($store->get('oauth-block-v2')?->status)->toBe(OutboxStatus::Pending)
        ->and($transport->calls)->toBe(1);

    (new OutboxOperator($store, $mappings))->resumeAuthentication('main', 'operator', 'oauth_reconciled');
    $processor->process('oauth-block-v1');
    expect($transport->calls)->toBe(2);
})->with([
    'uncertain token persistence' => [new OAuthTokenPersistenceUncertain('main'), 'oauth_token_persistence_uncertain'],
    'reauthorization required' => [new OAuthReauthorizationRequired('main'), 'oauth_reauthorization_required'],
]);

it('preserves transient OAuth endpoint throttling metadata', function () {
    Queue::fake();
    $clock = fixedOutboxClock();
    $store = new DatabaseOutboxStore(DB::getFacadeRoot(), $clock, 'outbox-test');
    $outbox = new ContactOutbox($store, new OutboxDispatcher(Queue::getFacadeRoot(), $store));
    $mappings = new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'outbox-test');
    $mappings->claim('main', new LocalContactReference('tenant:acme:crm-contact', 'contact-01'), 42);
    $transport = new class implements ContactSyncTransport
    {
        public function create(string $connection, ContactFields $fields): int
        {
            throw new LogicException('Create was not expected.');
        }

        public function update(string $connection, int $remoteId, ContactFields $fields): void
        {
            throw new OAuthTokenRequestFailed($connection, OAuthTokenFailureKind::Throttled, 429, 900);
        }
    };
    $outbox->record(updateOutboxCommand('oauth-throttled', 1));
    (new OutboxProcessor($store, $transport, $mappings, $clock, maximumDelaySeconds: 300))
        ->process('oauth-throttled');

    expect($store->get('oauth-throttled')?->status)->toBe(OutboxStatus::RetryWait)
        ->and($store->get('oauth-throttled')?->nextAttemptAt)->toBe(1_900)
        ->and($store->get('oauth-throttled')?->lastHttpStatus)->toBe(429);
});

it('safely retries a throttled create preflight before any create request is sent', function () {
    Queue::fake();
    $clock = fixedOutboxClock();
    $store = new DatabaseOutboxStore(DB::getFacadeRoot(), $clock, 'outbox-test');
    $outbox = new ContactOutbox($store, new OutboxDispatcher(Queue::getFacadeRoot(), $store));
    $transport = new class implements ContactSyncTransport
    {
        public function create(string $connection, ContactFields $fields): int
        {
            throw new MauticReadException(
                ReadFailureKind::Throttled,
                ReadOperation::SearchContacts,
                $connection,
                429,
                23,
            );
        }

        public function update(string $connection, int $remoteId, ContactFields $fields): void
        {
            throw new LogicException('Update was not expected.');
        }
    };
    $outbox->record(ContactSyncCommand::create(
        'preflight-throttled',
        'main',
        new LocalContactReference('tenant:acme', 'new-preflight'),
        1,
        ContactFields::fromArray(['email' => 'person@example.test'], ['email']),
    ));
    $processor = new OutboxProcessor(
        $store,
        $transport,
        new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'outbox-test'),
        $clock,
    );

    $processor->process('preflight-throttled');

    expect($store->get('preflight-throttled')?->status)->toBe(OutboxStatus::RetryWait)
        ->and($store->get('preflight-throttled')?->nextAttemptAt)->toBe(1_023)
        ->and($store->get('preflight-throttled')?->lastErrorCode)->toBe('create_preflight_throttled');
});

it('safely retries a create preflight transport failure before any mutation is sent', function () {
    Queue::fake();
    $clock = fixedOutboxClock();
    $store = new DatabaseOutboxStore(DB::getFacadeRoot(), $clock, 'outbox-test');
    $outbox = new ContactOutbox($store, new OutboxDispatcher(Queue::getFacadeRoot(), $store));
    $transport = new class implements ContactSyncTransport
    {
        public function create(string $connection, ContactFields $fields): int
        {
            throw new MauticReadException(ReadFailureKind::Transport, ReadOperation::SearchContacts, $connection);
        }

        public function update(string $connection, int $remoteId, ContactFields $fields): void
        {
            throw new LogicException('Update was not expected.');
        }
    };
    $outbox->record(ContactSyncCommand::create(
        'preflight-transport',
        'main',
        new LocalContactReference('tenant:acme', 'preflight-transport'),
        1,
        ContactFields::fromArray(['email' => 'person@example.test'], ['email']),
    ));

    (new OutboxProcessor(
        $store,
        $transport,
        new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'outbox-test'),
        $clock,
    ))->process('preflight-transport');

    expect($store->get('preflight-transport')?->status)->toBe(OutboxStatus::RetryWait)
        ->and($store->get('preflight-transport')?->lastErrorCode)->toBe('create_preflight_transport');
});

it('ignores a concurrent duplicate job while the aggregate lease is held', function () {
    Queue::fake();
    $clock = fixedOutboxClock();
    $store = new DatabaseOutboxStore(DB::getFacadeRoot(), $clock, 'outbox-test');
    $outbox = new ContactOutbox($store, new OutboxDispatcher(Queue::getFacadeRoot(), $store));
    $mappings = new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'outbox-test');
    $mappings->claim('main', new LocalContactReference('tenant:acme:crm-contact', 'contact-01'), 42);
    $processor = null;
    $transport = new class($processor) implements ContactSyncTransport
    {
        public int $calls = 0;

        public function __construct(public mixed &$processor) {}

        public function create(string $connection, ContactFields $fields): int
        {
            throw new LogicException('Create was not expected.');
        }

        public function update(string $connection, int $remoteId, ContactFields $fields): void
        {
            $this->calls++;
            $this->processor->process('concurrent');
        }
    };
    $processor = new OutboxProcessor($store, $transport, $mappings, $clock, leaseSeconds: 30, maxAttempts: 5);
    $transport->processor = $processor;
    $outbox->record(updateOutboxCommand('concurrent', 1));

    $processor->process('concurrent');

    expect($transport->calls)->toBe(1)
        ->and($store->get('concurrent')?->status)->toBe(OutboxStatus::Completed);
});
