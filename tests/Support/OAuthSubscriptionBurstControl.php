<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Tests\Support;

use PDO;
use RuntimeException;

final class OAuthSubscriptionBurstControl
{
    private readonly PDO $database;

    public function __construct(string $databasePath)
    {
        $this->database = new PDO('sqlite:'.$databasePath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->database->exec('PRAGMA busy_timeout = 15000');
        $this->database->exec('CREATE TABLE IF NOT EXISTS subscription_live_burst_control '
            .'(name TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $this->database->exec('CREATE TABLE IF NOT EXISTS subscription_live_burst_workers '
            .'(worker TEXT PRIMARY KEY, state TEXT NOT NULL)');
        $this->database->exec('CREATE TABLE IF NOT EXISTS subscription_live_burst_events '
            .'(id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, worker TEXT NOT NULL)');
        $this->database->exec('CREATE TABLE IF NOT EXISTS subscription_live_burst_barriers '
            .'(name TEXT PRIMARY KEY)');
    }

    public function initialize(int $workers, int $arrivals): void
    {
        if ($workers < 1 || $arrivals < 1) {
            throw new RuntimeException('Burst worker and arrival counts must be positive.');
        }
        $this->database->exec('BEGIN IMMEDIATE');
        try {
            $this->database->exec('DELETE FROM subscription_live_burst_control');
            $this->database->exec('DELETE FROM subscription_live_burst_workers');
            $this->database->exec('DELETE FROM subscription_live_burst_events');
            $this->database->exec('DELETE FROM subscription_live_burst_barriers');
            $statement = $this->database->prepare(
                'INSERT INTO subscription_live_burst_control (name, value) VALUES (?, ?)',
            );
            foreach ([
                'expected_workers' => $workers,
                'expected_arrivals' => $arrivals,
                'produced_arrivals' => 0,
                'producer_complete' => 0,
                'stop_requested' => 0,
            ] as $name => $value) {
                $statement->execute([$name, (string) $value]);
            }
            $this->database->exec('COMMIT');
        } catch (\Throwable $exception) {
            $this->database->exec('ROLLBACK');
            throw $exception;
        }
    }

    public function workerReady(string $worker): void
    {
        $statement = $this->database->prepare(
            "INSERT INTO subscription_live_burst_workers (worker, state) VALUES (?, 'active') "
            ."ON CONFLICT(worker) DO UPDATE SET state = 'active'",
        );
        $statement->execute([$worker]);
        $this->event('worker_ready', $worker);
    }

    public function workerDone(string $worker): void
    {
        $statement = $this->database->prepare(
            "INSERT INTO subscription_live_burst_workers (worker, state) VALUES (?, 'done') "
            ."ON CONFLICT(worker) DO UPDATE SET state = 'done'",
        );
        $statement->execute([$worker]);
        $this->event('worker_done', $worker);
    }

    public function waitUntilReady(float $timeoutSeconds): float
    {
        $started = hrtime(true);
        $deadline = $started + (int) ($timeoutSeconds * 1_000_000_000);
        $expected = $this->integer('expected_workers');
        while ($this->activeWorkers() < $expected) {
            if (hrtime(true) >= $deadline) {
                throw new RuntimeException('Live subscription burst worker-ready barrier timed out.');
            }
            usleep(10_000);
        }

        return (hrtime(true) - $started) / 1_000_000_000;
    }

    public function arrivalCommitted(): void
    {
        $this->database->exec('BEGIN IMMEDIATE');
        try {
            $produced = $this->integer('produced_arrivals') + 1;
            if ($produced > $this->integer('expected_arrivals')) {
                throw new RuntimeException('The live burst produced too many arrivals.');
            }
            $this->set('produced_arrivals', $produced);
            $this->database->exec('COMMIT');
        } catch (\Throwable $exception) {
            $this->database->exec('ROLLBACK');
            throw $exception;
        }
    }

    public function producerComplete(): void
    {
        if ($this->integer('produced_arrivals') !== $this->integer('expected_arrivals')) {
            throw new RuntimeException('The live burst producer cannot finish before every arrival is committed.');
        }
        $this->set('producer_complete', 1);
        $this->event('producer_complete', 'parent');
    }

    public function productionIsComplete(): bool
    {
        return $this->integer('producer_complete') === 1;
    }

    public function producedArrivals(): int
    {
        return $this->integer('produced_arrivals');
    }

    public function requestStop(): void
    {
        $this->set('stop_requested', 1);
        $this->event('stop_requested', 'parent');
    }

    public function stopIsRequested(): bool
    {
        return $this->integer('stop_requested') === 1;
    }

    public function assertNoActiveWorkers(): void
    {
        if ($this->activeWorkers() !== 0) {
            throw new RuntimeException('Remote cleanup cannot begin while burst workers are active.');
        }
    }

    public function activeWorkers(): int
    {
        return (int) $this->database->query(
            "SELECT COUNT(*) FROM subscription_live_burst_workers WHERE state = 'active'",
        )->fetchColumn();
    }

    public function event(string $name, string $worker): void
    {
        $statement = $this->database->prepare(
            'INSERT INTO subscription_live_burst_events (name, worker) VALUES (?, ?)',
        );
        $statement->execute([$name, $worker]);
    }

    public function eventCount(string $name, bool $distinctWorkers = false): int
    {
        $column = $distinctWorkers ? 'COUNT(DISTINCT worker)' : 'COUNT(*)';
        $statement = $this->database->prepare(
            "SELECT {$column} FROM subscription_live_burst_events WHERE name = ?",
        );
        $statement->execute([$name]);

        return (int) $statement->fetchColumn();
    }

    public function claimBarrier(string $name): bool
    {
        $statement = $this->database->prepare(
            'INSERT OR IGNORE INTO subscription_live_burst_barriers (name) VALUES (?)',
        );
        $statement->execute([$name]);

        return $statement->rowCount() === 1;
    }

    private function integer(string $name): int
    {
        $statement = $this->database->prepare(
            'SELECT value FROM subscription_live_burst_control WHERE name = ?',
        );
        $statement->execute([$name]);
        $value = $statement->fetchColumn();
        if (! is_string($value) || preg_match('/^(0|[1-9][0-9]*)$/D', $value) !== 1) {
            throw new RuntimeException('Live subscription burst control state is invalid.');
        }

        return (int) $value;
    }

    private function set(string $name, int $value): void
    {
        $statement = $this->database->prepare(
            'UPDATE subscription_live_burst_control SET value = ? WHERE name = ?',
        );
        $statement->execute([(string) $value, $name]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Live subscription burst control state is missing.');
        }
    }
}
