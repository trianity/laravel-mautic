<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Contracts\ContactSyncTransport;
use Trianity\LaravelMautic\Exceptions\InvalidConfiguration;
use Trianity\LaravelMautic\Exceptions\MauticReadException;
use Trianity\LaravelMautic\Exceptions\MauticWriteException;
use Trianity\LaravelMautic\Exceptions\OutboxConflict;
use Trianity\LaravelMautic\Identity\DatabaseContactMappingRepository;
use Trianity\LaravelMautic\Identity\LocalContactReference;
use Trianity\LaravelMautic\Jobs\ProcessContactOutboxJob;
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

it('requires the shared processing lease to exceed every remote request timeout', function () {
    config()->set('mautic.outbox.lease_seconds', 10);
    config()->set('mautic.connections.main.timeout', 10);
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

it('serializes duplicate jobs, supersedes stale revisions and isolates connections', function () {
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
    $processor->process('main-v1');
    $processor->process('main-v2');
    $processor->process('main-v2');
    $processor->process('secondary-v1');

    expect($store->get('main-v1')?->status)->toBe(OutboxStatus::Superseded)
        ->and($store->get('main-v2')?->status)->toBe(OutboxStatus::Completed)
        ->and($store->get('secondary-v1')?->status)->toBe(OutboxStatus::Completed)
        ->and($transport->updates)->toBe([
            ['main', 42, ['firstname' => 'Revision 2']],
            ['secondary', 84, ['firstname' => 'Revision 1']],
        ]);
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
        ->and($store->get('create-crashed')?->lastErrorCode)->toBe('create_worker_lost');

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
