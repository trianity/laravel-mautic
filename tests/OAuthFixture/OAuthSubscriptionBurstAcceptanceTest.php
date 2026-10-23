<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Exceptions\ContactNotFound;
use Trianity\LaravelMautic\Exceptions\SegmentNotFound;
use Trianity\LaravelMautic\Identity\DatabaseContactMappingRepository;
use Trianity\LaravelMautic\Identity\LocalContactReference;
use Trianity\LaravelMautic\Outbox\ContactOutbox;
use Trianity\LaravelMautic\Outbox\DatabaseOutboxStore;
use Trianity\LaravelMautic\Outbox\OutboxDispatcher;
use Trianity\LaravelMautic\Restriction\ManualEmailBlock;
use Trianity\LaravelMautic\Segment\StaticSegmentDefinition;
use Trianity\LaravelMautic\Subscription\DatabaseSubscriptionStore;
use Trianity\LaravelMautic\Subscription\SegmentTarget;
use Trianity\LaravelMautic\Subscription\SubscriptionCommand;
use Trianity\LaravelMautic\Subscription\SubscriptionDelivery;
use Trianity\LaravelMautic\Subscription\SubscriptionDispatcher;
use Trianity\LaravelMautic\Subscription\SubscriptionPoller;
use Trianity\LaravelMautic\Tests\Support\OAuthAcceptanceHarness;
use Trianity\LaravelMautic\Tests\Support\OAuthAcceptanceRequestBudget;
use Trianity\LaravelMautic\Tests\Support\OAuthFixtureConfiguration;
use Trianity\LaravelMautic\Tests\Support\OAuthSubscriptionEvidence;
use Trianity\LaravelMautic\Write\ContactFields;

function runOAuthSubscriptionBurstWorkers(string $databasePath, string $scenario, string $mode): void
{
    $workers = 4;
    $helper = dirname(__DIR__).'/Support/oauth_subscription_burst_worker.php';
    $processes = [];
    foreach (range(0, $workers - 1) as $worker) {
        $process = proc_open([
            PHP_BINARY, $helper, $databasePath, $scenario, (string) $worker, $mode, (string) $workers,
        ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start a live subscription burst worker.');
        }
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }
    foreach ($processes as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $stdout."\n".$stderr);
    }
}

it('verifies a 30-contact subscription burst through OAuth', function (string $scenario) {
    if (getenv('MAUTIC_OAUTH_SUBSCRIPTION_BURST_TESTS') !== '1') {
        test()->markTestSkipped('Set MAUTIC_OAUTH_SUBSCRIPTION_BURST_TESTS=1 for the live 30-contact subscription burst.');
    }
    $interval = filter_var(getenv('MAUTIC_OAUTH_SUBSCRIPTION_BURST_INTERVAL_SECONDS') ?: '60', FILTER_VALIDATE_INT);
    if (! is_int($interval) || $interval < 60 || $interval > 120) {
        throw new RuntimeException('MAUTIC_OAUTH_SUBSCRIPTION_BURST_INTERVAL_SECONDS must be between 60 and 120.');
    }
    $configuration = OAuthFixtureConfiguration::fromEnvironment();
    // Each of the 30 contacts requires an ownership read, deletion, and
    // not-found verification. The segment uses the same three-request cleanup.
    $budget = new OAuthAcceptanceRequestBudget(operationLimit: 120, cleanupLimit: 100);
    $harness = new OAuthAcceptanceHarness($configuration->oauth, requestBudget: $budget);
    $connection = $harness->connection();
    $clock = app(Clock::class);
    $runId = bin2hex(random_bytes(12));
    $evidence = new OAuthSubscriptionEvidence('burst-'.$scenario.'-'.$runId);
    $segmentName = 'Laravel Mautic burst '.$scenario.' '.$runId;
    $segmentAlias = 'lm-burst-'.$scenario.'-'.$runId;
    $segment = $scenario === 'existing'
        ? $connection->staticSegments()->create(new StaticSegmentDefinition($segmentName, $segmentAlias))
        : null;
    if ($scenario === 'missing' && $connection->staticSegments()->candidates($segmentAlias) !== []) {
        throw new RuntimeException('The unique missing-segment live target already exists.');
    }

    $databasePath = sys_get_temp_dir().'/laravel-mautic-subscription-burst-'.$runId.'.sqlite';
    touch($databasePath);
    chmod($databasePath, 0600);
    config()->set('database.connections.oauth-subscription-burst', [
        'driver' => 'sqlite', 'database' => $databasePath, 'prefix' => '',
    ]);
    foreach (['identity', 'outbox', 'subscription'] as $section) {
        config()->set('mautic.'.$section.'.database_connection', 'oauth-subscription-burst');
    }
    DB::purge('oauth-subscription-burst');
    (require dirname(__DIR__, 2).'/database/migrations/create_mautic_contact_mappings.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/create_mautic_contact_outbox.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/create_mautic_subscription_delivery.php.stub')->up();
    $schema = DB::connection('oauth-subscription-burst')->getSchemaBuilder();
    $schema->create('subscription_live_burst_barriers', function (Blueprint $table): void {
        $table->string('name')->primary();
    });
    $schema->create('subscription_live_burst_events', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('worker');
    });
    $schema->create('subscription_live_burst_worker_requests', function (Blueprint $table): void {
        $table->string('worker')->primary();
        $table->unsignedInteger('attempts');
    });

    Queue::fake();
    $mappings = new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'oauth-subscription-burst');
    $outbox = new DatabaseOutboxStore(DB::getFacadeRoot(), $clock, 'oauth-subscription-burst');
    $store = new DatabaseSubscriptionStore(DB::getFacadeRoot(), $clock, 'oauth-subscription-burst');
    $delivery = new SubscriptionDelivery(
        $store,
        new ContactOutbox($outbox, new OutboxDispatcher(Queue::getFacadeRoot(), $outbox)),
        $mappings,
        new SubscriptionDispatcher(Queue::getFacadeRoot()),
    );
    $emails = [];
    $commands = [];
    $ownedContactIds = [];
    $firstEmail = 'lm-burst-'.$runId.'-00@example.test';
    $firstContact = $connection->contactWrites()->create(ContactFields::fromArray([
        'email' => $firstEmail, 'firstname' => 'BurstRestricted',
    ], ['email', 'firstname']));
    $ownedContactIds[] = $firstContact->remoteId;
    $firstReference = new LocalContactReference('oauth-subscription-burst', $runId.'-00');
    $mappings->claim('oauth-acceptance', $firstReference, $firstContact->remoteId);
    $connection->restrictionWrites()->blockEmail(
        $firstContact->remoteId,
        ManualEmailBlock::record('Subscription burst DNC '.$runId, $clock),
    );
    $initialDnc = $connection->contactReads()->find($firstContact->remoteId)->doNotContact;
    $failure = null;
    $cleanupFailure = null;
    $remoteSegmentId = $segment?->remoteId;

    try {
        $arrivalStarted = microtime(true);
        foreach (range(0, 29) as $index) {
            if ($index > 0) {
                usleep((int) floor(($interval * 1_000_000) / 29));
            }
            $email = sprintf('lm-burst-%s-%02d@example.test', $runId, $index);
            $reference = $index === 0
                ? $firstReference
                : new LocalContactReference('oauth-subscription-burst', $runId.'-'.sprintf('%02d', $index));
            $target = $scenario === 'existing'
                ? SegmentTarget::existing('burst-'.$runId, $remoteSegmentId ?? throw new RuntimeException('Owned segment missing.'))
                : SegmentTarget::createIfAbsent(
                    'burst-'.$runId,
                    new StaticSegmentDefinition($segmentName, $segmentAlias),
                );
            $command = new SubscriptionCommand(
                'oauth-acceptance', $reference, 'accepted-'.$runId.'-'.$index, 1,
                ContactFields::fromArray(['email' => $email, 'firstname' => 'Burst'.sprintf('%02d', $index)], ['email', 'firstname']),
                $target,
            );
            DB::connection('oauth-subscription-burst')->transaction(fn () => $delivery->record($command));
            $emails[] = $email;
            $commands[] = $command;
        }
        $arrivalElapsed = microtime(true) - $arrivalStarted;
        if ($arrivalElapsed < 59 || $arrivalElapsed > 125) {
            throw new RuntimeException('The live arrival window fell outside the configured bounded interval.');
        }

        runOAuthSubscriptionBurstWorkers($databasePath, $scenario, 'initial');
        $drainStarted = microtime(true);
        for ($round = 0; $round < 5 && $store->recoverable(100) !== []; $round++) {
            (new SubscriptionPoller($store, new SubscriptionDispatcher(Queue::getFacadeRoot())))->recover(100);
            runOAuthSubscriptionBurstWorkers($databasePath, $scenario, 'recovery-'.$round);
        }
        $drainElapsed = microtime(true) - $drainStarted;
        $remoteSegmentId = $store->associatedSegment('oauth-acceptance', 'burst-'.$runId);
        if ($remoteSegmentId === null) {
            throw new RuntimeException('The live burst did not retain its segment association.');
        }
        $mapped = DB::connection('oauth-subscription-burst')->table('mautic_contact_mappings')->pluck('remote_id')->all();
        $ownedContactIds = array_values(array_unique(array_map('intval', $mapped)));
        foreach ($ownedContactIds as $contactId) {
            $memberships = $connection->contactReads()->segments($contactId)->memberships;
            if (! array_any($memberships, static fn ($membership): bool => $membership->remoteId === $remoteSegmentId)) {
                throw new RuntimeException('An owned live contact is missing the exact burst membership.');
            }
        }
        expect(DB::connection('oauth-subscription-burst')->table('mautic_subscription_intents')->count())->toBe(30)
            ->and(DB::connection('oauth-subscription-burst')->table('mautic_contact_mappings')->count())->toBe(30)
            ->and(DB::connection('oauth-subscription-burst')->table('mautic_subscription_intents')->where('status', 'completed')->count())->toBe(30)
            ->and(DB::connection('oauth-subscription-burst')->table('mautic_subscription_segments')->count())->toBe(1)
            ->and(DB::connection('oauth-subscription-burst')->table('subscription_live_burst_events')
                ->where('name', 'segment_create_dispatch')->count())->toBe($scenario === 'missing' ? 1 : 0)
            ->and(DB::connection('oauth-subscription-burst')->table('subscription_live_burst_events')
                ->where('name', 'contender_pending')->count())->toBeGreaterThanOrEqual(3)
            ->and(DB::connection('oauth-subscription-burst')->table('mautic_subscription_intents')
                ->whereIn('status', ['failed', 'blocked', 'ambiguous'])->count())->toBe(0)
            ->and($connection->contactReads()->find($firstContact->remoteId)->doNotContact)->toEqual($initialDnc)
            ->and($drainElapsed)->toBeLessThan(120.0);
        foreach ([$commands[0], $commands[14], $commands[29]] as $command) {
            DB::connection('oauth-subscription-burst')->transaction(fn () => $delivery->record($command));
        }
        expect(DB::connection('oauth-subscription-burst')->table('mautic_subscription_intents')->count())->toBe(30);
        $evidence->completed();
    } catch (Throwable $exception) {
        $failure = $exception;
    }

    $budget->beginCleanup();
    try {
        $outboxContactIds = DB::connection('oauth-subscription-burst')->table('mautic_outbox_commands')
            ->whereNotNull('remote_id')->pluck('remote_id')->all();
        $ownedContactIds = array_values(array_unique(array_merge(
            $ownedContactIds,
            array_map('intval', $outboxContactIds),
        )));
        if ($remoteSegmentId === null) {
            $retainedSegmentId = DB::connection('oauth-subscription-burst')
                ->table('mautic_subscription_segment_locks')->whereNotNull('remote_id')->value('remote_id');
            $remoteSegmentId = is_numeric($retainedSegmentId) ? (int) $retainedSegmentId : null;
        }
        $emailById = [];
        $mappingRows = DB::connection('oauth-subscription-burst')->table('mautic_contact_mappings')->get()->all();
        foreach ($mappingRows as $mappingRow) {
            $index = (int) substr((string) $mappingRow->local_key, -2);
            $emailById[(int) $mappingRow->remote_id] = $emails[$index] ?? null;
        }
        foreach ($ownedContactIds as $contactId) {
            $stored = $connection->contactReads()->find($contactId);
            $storedEmail = $stored->field('email')->value();
            if (($emailById[$contactId] ?? $storedEmail) !== $storedEmail
                || ! is_string($storedEmail) || ! in_array($storedEmail, $emails, true)) {
                throw new RuntimeException('Owned live contact identity changed before cleanup.');
            }
            $connection->contacts()->delete($contactId);
            expect(fn () => $connection->contactReads()->find($contactId))->toThrow(ContactNotFound::class);
        }
        if (is_int($remoteSegmentId)) {
            $storedSegment = $connection->staticSegments()->find($remoteSegmentId);
            expect($storedSegment->name)->toBe($segmentName)->and($storedSegment->alias)->toBe($segmentAlias);
            $connection->api('segments')->delete($remoteSegmentId);
            expect(fn () => $connection->staticSegments()->find($remoteSegmentId))->toThrow(SegmentNotFound::class);
        }
        $evidence->cleanup('deleted');
    } catch (Throwable $exception) {
        $cleanupFailure = $exception;
        $evidence->cleanup('failed');
    }
    $workerAttempts = (int) DB::connection('oauth-subscription-burst')
        ->table('subscription_live_burst_worker_requests')->sum('attempts');
    $evidence->requestCounts($budget->operationAttempts() + $workerAttempts, $budget->cleanupAttempts());
    expect($budget->operationAttempts() + $workerAttempts)->toBeLessThanOrEqual(500)
        ->and($budget->cleanupAttempts())->toBeLessThanOrEqual(100);
    if ($failure === null && $cleanupFailure === null) {
        DB::purge('oauth-subscription-burst');
        unlink($databasePath);
    }
    if ($failure !== null) {
        throw $failure;
    }
    if ($cleanupFailure !== null) {
        throw $cleanupFailure;
    }
})->with(['existing', 'missing']);
