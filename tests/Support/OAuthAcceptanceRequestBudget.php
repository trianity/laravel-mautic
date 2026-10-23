<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Tests\Support;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class OAuthAcceptanceRequestBudget
{
    public const OPERATION_LIMIT = 16;

    public const CLEANUP_LIMIT = 5;

    private int $operationAttempts = 0;

    private int $cleanupAttempts = 0;

    private bool $cleanup = false;

    private bool $operationRejected = false;

    private bool $cleanupRejected = false;

    private ?PDO $shared = null;

    private string $consumer = 'local';

    private bool $cleanupAllowed = true;

    public function __construct(
        private readonly int $operationLimit = self::OPERATION_LIMIT,
        private readonly int $cleanupLimit = self::CLEANUP_LIMIT,
    ) {
        if ($operationLimit < 1 || $cleanupLimit < 0) {
            throw new InvalidArgumentException('The OAuth operation limit must be positive and cleanup limit non-negative.');
        }
    }

    public static function shared(
        string $databasePath,
        string $consumer,
        int $operationLimit,
        int $cleanupLimit,
        bool $initialize = false,
        bool $cleanupAllowed = true,
    ): self {
        if ($databasePath === '' || $consumer === '') {
            throw new InvalidArgumentException('Shared OAuth budget path and consumer must be non-empty.');
        }

        $budget = new self($operationLimit, $cleanupLimit);
        $budget->shared = new PDO('sqlite:'.$databasePath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $budget->shared->exec('PRAGMA busy_timeout = 15000');
        $budget->consumer = $consumer;
        $budget->cleanupAllowed = $cleanupAllowed;
        $budget->createSharedSchema();
        if ($initialize) {
            $budget->initializeSharedState();
        } else {
            $budget->assertSharedState();
        }

        return $budget;
    }

    public function consume(): void
    {
        if ($this->shared !== null) {
            $this->consumeShared();

            return;
        }

        if ($this->cleanup) {
            if ($this->cleanupAttempts >= $this->cleanupLimit) {
                $this->cleanupRejected = true;
                throw new RuntimeException('OAuth acceptance cleanup request budget exhausted.');
            }

            $this->cleanupAttempts++;

            return;
        }

        if ($this->operationAttempts >= $this->operationLimit) {
            $this->operationRejected = true;
            throw new RuntimeException('OAuth acceptance operation request budget exhausted.');
        }

        $this->operationAttempts++;
    }

    public function beginCleanup(): void
    {
        if ($this->shared !== null) {
            if (! $this->cleanupAllowed) {
                throw new RuntimeException('This OAuth acceptance consumer cannot begin cleanup.');
            }
            $this->sharedTransaction(function (array $state): void {
                $statement = $this->shared?->prepare(
                    "UPDATE subscription_live_burst_budget SET phase = 'cleanup' WHERE id = 1",
                );
                $statement?->execute();
            });
        }
        $this->cleanup = true;
    }

    public function operationAttempts(): int
    {
        if ($this->shared !== null) {
            return $this->sharedCount('operation_attempts');
        }

        return $this->operationAttempts;
    }

    public function cleanupAttempts(): int
    {
        if ($this->shared !== null) {
            return $this->sharedCount('cleanup_attempts');
        }

        return $this->cleanupAttempts;
    }

    public function operationLimit(): int
    {
        return $this->operationLimit;
    }

    public function cleanupLimit(): int
    {
        return $this->cleanupLimit;
    }

    public function operationWasRejected(): bool
    {
        return $this->operationRejected;
    }

    public function cleanupWasRejected(): bool
    {
        return $this->cleanupRejected;
    }

    /** @return array<string, array{operation: int, cleanup: int}> */
    public function consumerAttempts(): array
    {
        if ($this->shared === null) {
            return [$this->consumer => [
                'operation' => $this->operationAttempts,
                'cleanup' => $this->cleanupAttempts,
            ]];
        }
        $rows = $this->shared->query(
            'SELECT consumer, operation_attempts, cleanup_attempts '
            .'FROM subscription_live_burst_budget_consumers ORDER BY consumer',
        )?->fetchAll(PDO::FETCH_ASSOC);
        $attempts = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $attempts[(string) $row['consumer']] = [
                'operation' => (int) $row['operation_attempts'],
                'cleanup' => (int) $row['cleanup_attempts'],
            ];
        }

        return $attempts;
    }

    private function createSharedSchema(): void
    {
        $this->shared?->exec('CREATE TABLE IF NOT EXISTS subscription_live_burst_budget ('
            .'id INTEGER PRIMARY KEY CHECK (id = 1), operation_limit INTEGER NOT NULL, '
            .'cleanup_limit INTEGER NOT NULL, operation_attempts INTEGER NOT NULL, '
            .'cleanup_attempts INTEGER NOT NULL, phase TEXT NOT NULL)');
        $this->shared?->exec('CREATE TABLE IF NOT EXISTS subscription_live_burst_budget_consumers ('
            .'consumer TEXT PRIMARY KEY, operation_attempts INTEGER NOT NULL, cleanup_attempts INTEGER NOT NULL)');
    }

    private function initializeSharedState(): void
    {
        $this->shared?->exec('BEGIN IMMEDIATE');
        try {
            $statement = $this->shared?->prepare('INSERT OR REPLACE INTO subscription_live_burst_budget '
                .'(id, operation_limit, cleanup_limit, operation_attempts, cleanup_attempts, phase) '
                ."VALUES (1, ?, ?, 0, 0, 'operation')");
            $statement?->execute([$this->operationLimit, $this->cleanupLimit]);
            $this->shared?->exec('DELETE FROM subscription_live_burst_budget_consumers');
            $this->shared?->commit();
        } catch (Throwable $exception) {
            $this->shared?->rollBack();
            throw $exception;
        }
    }

    private function assertSharedState(): void
    {
        $state = $this->sharedState();
        if ((int) $state['operation_limit'] !== $this->operationLimit
            || (int) $state['cleanup_limit'] !== $this->cleanupLimit) {
            throw new RuntimeException('Shared OAuth acceptance request limits do not match.');
        }
    }

    private function consumeShared(): void
    {
        $this->sharedTransaction(function (array $state): void {
            $cleanup = $state['phase'] === 'cleanup';
            if ($cleanup && ! $this->cleanupAllowed) {
                $this->operationRejected = true;
                throw new RuntimeException('OAuth acceptance operation requests are disabled after cleanup began.');
            }
            $kind = $cleanup ? 'cleanup' : 'operation';
            $attempts = (int) $state[$kind.'_attempts'];
            $limit = (int) $state[$kind.'_limit'];
            if ($attempts >= $limit) {
                if ($cleanup) {
                    $this->cleanupRejected = true;
                } else {
                    $this->operationRejected = true;
                }
                throw new RuntimeException('OAuth acceptance '.$kind.' request budget exhausted.');
            }
            $column = $kind.'_attempts';
            $this->shared?->exec("UPDATE subscription_live_burst_budget SET {$column} = {$column} + 1 WHERE id = 1");
            $statement = $this->shared?->prepare('INSERT INTO subscription_live_burst_budget_consumers '
                .'(consumer, operation_attempts, cleanup_attempts) VALUES (?, ?, ?) '
                .'ON CONFLICT(consumer) DO UPDATE SET '.$column.' = '.$column.' + 1');
            $statement?->execute([$this->consumer, $cleanup ? 0 : 1, $cleanup ? 1 : 0]);
        });
    }

    /** @param callable(array<string, int|string>): void $callback */
    private function sharedTransaction(callable $callback): void
    {
        $this->shared?->exec('BEGIN IMMEDIATE');
        try {
            $callback($this->sharedState());
            $this->shared?->commit();
        } catch (Throwable $exception) {
            $this->shared?->rollBack();
            throw $exception;
        }
    }

    /** @return array<string, int|string> */
    private function sharedState(): array
    {
        $state = $this->shared?->query('SELECT * FROM subscription_live_burst_budget WHERE id = 1')
            ?->fetch(PDO::FETCH_ASSOC);
        if (! is_array($state)) {
            throw new RuntimeException('Shared OAuth acceptance request budget is not initialized.');
        }

        return $state;
    }

    private function sharedCount(string $column): int
    {
        return (int) ($this->shared?->query(
            "SELECT {$column} FROM subscription_live_burst_budget WHERE id = 1",
        )?->fetchColumn() ?: 0);
    }
}
