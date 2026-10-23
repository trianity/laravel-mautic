<?php

declare(strict_types=1);

use PDO;
use Trianity\LaravelMautic\Tests\Support\OAuthAcceptanceRequestBudget;
use Trianity\LaravelMautic\Tests\Support\OAuthSubscriptionBurstControl;

/** @return array{0: resource, 1: array<int, resource>} */
function startBurstControlWorker(string $path, string $worker, int $deadlineMilliseconds): array
{
    $process = proc_open([
        PHP_BINARY,
        dirname(__DIR__).'/Support/oauth_subscription_burst_control_worker.php',
        $path,
        $worker,
        (string) $deadlineMilliseconds,
    ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Unable to start burst control worker.');
    }
    fclose($pipes[0]);

    return [$process, $pipes];
}

/** @param array{0: resource, 1: array<int, resource>} $worker */
function finishBurstControlWorker(array $worker): array
{
    [$process, $pipes] = $worker;
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
}

it('keeps workers alive through an empty queue and processes arrivals before producer completion', function () {
    $path = tempnam(sys_get_temp_dir(), 'mautic-burst-control-');
    if (! is_string($path)) {
        throw new RuntimeException('Unable to create burst control database.');
    }
    try {
        $control = new OAuthSubscriptionBurstControl($path);
        $control->initialize(1, 2);
        $worker = startBurstControlWorker($path, 'worker-0', 2_000);
        $control->waitUntilReady(1);
        usleep(50_000);
        expect(proc_get_status($worker[0])['running'])->toBeTrue()
            ->and(fn () => $control->assertNoActiveWorkers())
            ->toThrow(RuntimeException::class, 'while burst workers are active');

        $control->arrivalCommitted();
        $deadline = hrtime(true) + 500_000_000;
        while ($control->eventCount('arrival_processed') < 1 && hrtime(true) < $deadline) {
            usleep(5_000);
        }
        expect($control->eventCount('arrival_processed'))->toBe(1);

        $control->arrivalCommitted();
        $control->producerComplete();
        [$exit, $stdout, $stderr] = finishBurstControlWorker($worker);
        expect($exit)->toBe(0, $stdout."\n".$stderr)
            ->and($control->eventCount('arrival_processed'))->toBe(2)
            ->and($control->eventCount('worker_done'))->toBe(1);
        $control->assertNoActiveWorkers();
    } finally {
        @unlink($path);
    }
});

it('reports a bounded worker deadline explicitly', function () {
    $path = tempnam(sys_get_temp_dir(), 'mautic-burst-deadline-');
    if (! is_string($path)) {
        throw new RuntimeException('Unable to create burst control database.');
    }
    try {
        $control = new OAuthSubscriptionBurstControl($path);
        $control->initialize(1, 1);
        $worker = startBurstControlWorker($path, 'worker-0', 100);
        $control->waitUntilReady(1);
        [$exit] = finishBurstControlWorker($worker);
        expect($exit)->not->toBe(0)
            ->and($control->eventCount('worker_deadline'))->toBe(1);
        $control->assertNoActiveWorkers();
    } finally {
        @unlink($path);
    }
});

it('enforces one tiny shared transport allowance across concurrent and restarted workers', function () {
    $path = tempnam(sys_get_temp_dir(), 'mautic-burst-budget-');
    if (! is_string($path)) {
        throw new RuntimeException('Unable to create burst budget database.');
    }
    try {
        $budget = OAuthAcceptanceRequestBudget::shared($path, 'parent', 3, 2, initialize: true);
        $database = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $database->exec('CREATE TABLE subscription_live_burst_transport_attempts '
            .'(id INTEGER PRIMARY KEY, attempts INTEGER NOT NULL)');
        $database->exec('INSERT INTO subscription_live_burst_transport_attempts VALUES (1, 0)');
        $budget->consume();
        $workers = [];
        foreach (['worker-0', 'worker-1', 'worker-0-restart'] as $consumer) {
            $process = proc_open([
                PHP_BINARY,
                dirname(__DIR__).'/Support/oauth_shared_budget_worker.php',
                $path,
                $consumer,
                '3',
            ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start shared budget worker.');
            }
            fclose($pipes[0]);
            $workers[] = [$process, $pipes];
        }
        foreach ($workers as $worker) {
            [$exit, $stdout, $stderr] = finishBurstControlWorker($worker);
            expect($exit)->toBe(0, $stdout."\n".$stderr);
        }
        expect($budget->operationAttempts())->toBe(3)
            ->and((int) $database->query(
                'SELECT attempts FROM subscription_live_burst_transport_attempts WHERE id = 1',
            )->fetchColumn())->toBe(2)
            ->and($budget->consumerAttempts()['parent']['operation'])->toBe(1);

        $budget->beginCleanup();
        $budget->consume();
        expect($budget->cleanupAttempts())->toBe(1)
            ->and($budget->operationAttempts())->toBe(3);
    } finally {
        @unlink($path);
    }
});
