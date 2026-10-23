<?php

declare(strict_types=1);

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
use Trianity\LaravelMautic\Subscription\SubscriptionStatus;
use Trianity\LaravelMautic\Tests\Support\OAuthAcceptanceHarness;
use Trianity\LaravelMautic\Tests\Support\OAuthAcceptanceRequestBudget;
use Trianity\LaravelMautic\Tests\Support\OAuthFixtureConfiguration;
use Trianity\LaravelMautic\Tests\Support\OAuthSubscriptionBurstControl;
use Trianity\LaravelMautic\Tests\Support\OAuthSubscriptionEvidence;
use Trianity\LaravelMautic\Write\ContactFields;

/** @return list<array{process: resource, pipes: array<int, resource>}> */
function startOAuthSubscriptionBurstWorkers(
    string $databasePath,
    string $coordinationPath,
    string $scenario,
    int $lifetimeSeconds,
): array {
    $processes = [];
    $helper = dirname(__DIR__).'/Support/oauth_subscription_burst_worker.php';
    foreach (range(0, 3) as $worker) {
        $process = proc_open([
            PHP_BINARY, $helper, $databasePath, $coordinationPath, $scenario,
            (string) $worker, '4', (string) $lifetimeSeconds,
        ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            foreach ($processes as $entry) {
                proc_terminate($entry['process']);
                fclose($entry['pipes'][1]);
                fclose($entry['pipes'][2]);
                proc_close($entry['process']);
            }
            throw new RuntimeException('Unable to start a live subscription burst worker.');
        }
        fclose($pipes[0]);
        $processes[] = ['process' => $process, 'pipes' => $pipes];
    }

    return $processes;
}

/**
 * @param  list<array{process: resource, pipes: array<int, resource>}>  $processes
 * @return array{forced: bool, errors: list<string>}
 */
function reapOAuthSubscriptionBurstWorkers(
    array $processes,
    OAuthSubscriptionBurstControl $control,
    bool $requestStop,
): array {
    $errors = [];
    if ($requestStop) {
        try {
            $control->requestStop();
        } catch (Throwable $exception) {
            $errors[] = 'Worker stop signalling failed: '.$exception->getMessage();
        }
    }
    $deadline = hrtime(true) + 5_000_000_000;
    do {
        $running = false;
        foreach ($processes as $entry) {
            if (proc_get_status($entry['process'])['running']) {
                $running = true;
            }
        }
        if ($running) {
            usleep(20_000);
        }
    } while ($running && hrtime(true) < $deadline);

    $forced = false;
    foreach ($processes as $entry) {
        $status = proc_get_status($entry['process']);
        if ($status['running']) {
            $forced = true;
            proc_terminate($entry['process']);
            usleep(50_000);
            $status = proc_get_status($entry['process']);
            if ($status['running']) {
                proc_terminate($entry['process'], 9);
                usleep(50_000);
                $status = proc_get_status($entry['process']);
            }
        }
        $stdout = stream_get_contents($entry['pipes'][1]);
        $stderr = stream_get_contents($entry['pipes'][2]);
        fclose($entry['pipes'][1]);
        fclose($entry['pipes'][2]);
        $closed = proc_close($entry['process']);
        $exit = $status['exitcode'] >= 0 ? $status['exitcode'] : $closed;
        if ($exit !== 0) {
            $errors[] = trim($stdout."\n".$stderr) ?: 'Live subscription burst worker exited unsuccessfully.';
        }
    }

    return ['forced' => $forced, 'errors' => $errors];
}

it('verifies a continuous 30-contact subscription burst through OAuth', function (string $scenario) {
    if (getenv('MAUTIC_OAUTH_SUBSCRIPTION_BURST_TESTS') !== '1') {
        test()->markTestSkipped('Set MAUTIC_OAUTH_SUBSCRIPTION_BURST_TESTS=1 for the live 30-contact subscription burst.');
    }
    $interval = filter_var(getenv('MAUTIC_OAUTH_SUBSCRIPTION_BURST_INTERVAL_SECONDS') ?: '60', FILTER_VALIDATE_INT);
    if (! is_int($interval) || $interval < 60 || $interval > 120) {
        throw new RuntimeException('MAUTIC_OAUTH_SUBSCRIPTION_BURST_INTERVAL_SECONDS must be between 60 and 120.');
    }

    $runId = bin2hex(random_bytes(12));
    $databasePath = sys_get_temp_dir().'/laravel-mautic-subscription-burst-'.$runId.'.sqlite';
    $coordinationPath = $databasePath.'.coordination';
    touch($databasePath);
    chmod($databasePath, 0600);
    touch($coordinationPath);
    chmod($coordinationPath, 0600);
    $budget = OAuthAcceptanceRequestBudget::shared($coordinationPath, 'parent', 500, 100, initialize: true);
    $control = new OAuthSubscriptionBurstControl($coordinationPath);
    $control->initialize(4, 30);
    $configuration = OAuthFixtureConfiguration::fromEnvironment();
    $harness = new OAuthAcceptanceHarness($configuration->oauth, requestBudget: $budget);
    $connection = $harness->connection();
    $clock = app(Clock::class);
    $evidence = new OAuthSubscriptionEvidence('continuous-burst-'.$scenario.'-'.$runId);
    $segmentName = 'Laravel Mautic burst '.$scenario.' '.$runId;
    $segmentAlias = 'lm-burst-'.$scenario.'-'.$runId;

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
    $remoteSegmentId = null;
    $failure = null;
    $cleanupFailure = null;
    $processes = [];
    $forcedShutdown = false;
    $scenarioStarted = hrtime(true);
    $workerReadyAt = null;
    $firstArrivalAt = null;
    $lastArrivalAt = null;
    $lastCompletionAt = null;
    $cleanupDuration = 0.0;

    try {
        $segment = $scenario === 'existing'
            ? $connection->staticSegments()->create(new StaticSegmentDefinition($segmentName, $segmentAlias))
            : null;
        if ($scenario === 'missing' && $connection->staticSegments()->candidates($segmentAlias) !== []) {
            throw new RuntimeException('The unique missing-segment live target already exists.');
        }
        $remoteSegmentId = $segment?->remoteId;
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

        $processes = startOAuthSubscriptionBurstWorkers(
            $databasePath,
            $coordinationPath,
            $scenario,
            $interval + 150,
        );
        $control->waitUntilReady(20);
        $workerReadyAt = hrtime(true);
        $scheduleStarted = hrtime(true);
        $completedBeforeFinalArrival = false;
        foreach (range(0, 29) as $index) {
            if ($index > 0) {
                $target = $scheduleStarted + (int) (($interval * 1_000_000_000 * $index) / 29);
                while (hrtime(true) < $target) {
                    usleep((int) min(20_000, max(1, ($target - hrtime(true)) / 1_000)));
                }
            }
            if ($index === 29) {
                $completedBeforeFinalArrival = DB::connection('oauth-subscription-burst')
                    ->table('mautic_subscription_intents')
                    ->where('status', SubscriptionStatus::Completed->value)->exists();
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
                ContactFields::fromArray([
                    'email' => $email, 'firstname' => 'Burst'.sprintf('%02d', $index),
                ], ['email', 'firstname']),
                $target,
            );
            DB::connection('oauth-subscription-burst')->transaction(fn () => $delivery->record($command));
            $control->arrivalCommitted();
            $committedAt = hrtime(true);
            $firstArrivalAt ??= $committedAt;
            $lastArrivalAt = $committedAt;
            $emails[] = $email;
            $commands[] = $command;
        }
        $control->producerComplete();
        if (! $completedBeforeFinalArrival) {
            throw new RuntimeException('No subscription completed before the final arrival was committed.');
        }
        $arrivalElapsed = (($lastArrivalAt ?? 0) - ($firstArrivalAt ?? 0)) / 1_000_000_000;
        if ($arrivalElapsed < 59 || $arrivalElapsed > 125) {
            throw new RuntimeException('The live arrival window fell outside the configured bounded interval.');
        }

        $completionDeadline = ($lastArrivalAt ?? hrtime(true)) + 120_000_000_000;
        while (DB::connection('oauth-subscription-burst')->table('mautic_subscription_intents')
            ->where('status', SubscriptionStatus::Completed->value)->count() < 30) {
            if ($control->eventCount('worker_failed') > 0) {
                throw new RuntimeException('A live subscription burst worker failed before completion.');
            }
            if (hrtime(true) >= $completionDeadline) {
                throw new RuntimeException('Live subscription burst completion deadline exhausted.');
            }
            usleep(25_000);
        }
        $lastCompletionAt = hrtime(true);
        $reaped = reapOAuthSubscriptionBurstWorkers($processes, $control, requestStop: false);
        $processes = [];
        $forcedShutdown = $reaped['forced'];
        if ($forcedShutdown || $reaped['errors'] !== []) {
            throw new RuntimeException('Live subscription burst workers did not stop cleanly: '.implode(' | ', $reaped['errors']));
        }
        $control->assertNoActiveWorkers();

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
            ->and(DB::connection('oauth-subscription-burst')->table('mautic_subscription_intents')
                ->where('status', SubscriptionStatus::Completed->value)->count())->toBe(30)
            ->and(DB::connection('oauth-subscription-burst')->table('mautic_subscription_segments')->count())->toBe(1)
            ->and($control->eventCount('segment_create_dispatch'))->toBe($scenario === 'missing' ? 1 : 0)
            ->and($control->eventCount('contender_pending', distinctWorkers: true))->toBeGreaterThanOrEqual(3)
            ->and(DB::connection('oauth-subscription-burst')->table('mautic_subscription_intents')
                ->whereIn('status', ['failed', 'blocked', 'ambiguous'])->count())->toBe(0)
            ->and($connection->contactReads()->find($firstContact->remoteId)->doNotContact)->toEqual($initialDnc);
        foreach ([$commands[0], $commands[14], $commands[29]] as $command) {
            DB::connection('oauth-subscription-burst')->transaction(fn () => $delivery->record($command));
        }
        expect(DB::connection('oauth-subscription-burst')->table('mautic_subscription_intents')->count())->toBe(30);
        $evidence->completed();
    } catch (Throwable $exception) {
        $failure = $exception;
    }

    if ($processes !== []) {
        $reaped = reapOAuthSubscriptionBurstWorkers($processes, $control, requestStop: true);
        $processes = [];
        $forcedShutdown = $forcedShutdown || $reaped['forced'];
        if ($failure === null && $reaped['errors'] !== []) {
            $failure = new RuntimeException('Live subscription burst worker shutdown failed: '.implode(' | ', $reaped['errors']));
        }
    }

    $completedCount = (int) DB::connection('oauth-subscription-burst')->table('mautic_subscription_intents')
        ->where('status', SubscriptionStatus::Completed->value)->count();
    $cleanupSafe = ! $forcedShutdown && $control->activeWorkers() === 0
        && ($failure === null || $completedCount === 30 || $control->eventCount('worker_ready') === 0);
    if ($cleanupSafe) {
        $control->assertNoActiveWorkers();
        $budget->beginCleanup();
        $cleanupStarted = hrtime(true);
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
            foreach (DB::connection('oauth-subscription-burst')->table('mautic_contact_mappings')->get()->all() as $row) {
                $index = (int) substr((string) $row->local_key, -2);
                $emailById[(int) $row->remote_id] = $emails[$index] ?? null;
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
        $cleanupDuration = (hrtime(true) - $cleanupStarted) / 1_000_000_000;
    } else {
        $evidence->cleanup('failed');
        $cleanupFailure = new RuntimeException('Remote cleanup was withheld because worker mutation uncertainty remains.');
    }

    $evidence->requestCounts($budget->operationAttempts(), $budget->cleanupAttempts());
    $evidence->consumerRequestCounts($budget->consumerAttempts());
    expect($budget->operationAttempts())->toBeLessThanOrEqual(500)
        ->and($budget->cleanupAttempts())->toBeLessThanOrEqual(100);
    if ($workerReadyAt !== null && $firstArrivalAt !== null && $lastArrivalAt !== null && $lastCompletionAt !== null) {
        $evidence->timings(
            ($workerReadyAt - $scenarioStarted) / 1_000_000_000,
            ($firstArrivalAt - $scenarioStarted) / 1_000_000_000,
            ($lastArrivalAt - $scenarioStarted) / 1_000_000_000,
            ($lastCompletionAt - $scenarioStarted) / 1_000_000_000,
            ($lastArrivalAt - $firstArrivalAt) / 1_000_000_000,
            ($lastCompletionAt - $firstArrivalAt) / 1_000_000_000,
            max(0.0, ($lastCompletionAt - $lastArrivalAt) / 1_000_000_000),
            $cleanupDuration,
        );
    }
    if ($failure === null && $cleanupFailure === null) {
        DB::purge('oauth-subscription-burst');
        unlink($databasePath);
        unlink($coordinationPath);
    }
    if ($failure !== null) {
        throw $failure;
    }
    if ($cleanupFailure !== null) {
        throw $cleanupFailure;
    }
})->with(['existing', 'missing']);
