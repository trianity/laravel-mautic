<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Tests\Support;

use RuntimeException;

final class OAuthSubscriptionEvidence
{
    private readonly string $path;

    /** @var array<string, array<string, array{cleanup: int, operation: int}>|float|int|string> */
    private array $evidence;

    public function __construct(string $runId, ?string $storageDirectory = null)
    {
        $directory = $storageDirectory ?? dirname(__DIR__).'/.oauth-acceptance';
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            throw new RuntimeException('The subscription evidence directory could not be created.');
        }
        if (! chmod($directory, 0700)) {
            throw new RuntimeException('The subscription evidence directory could not be restricted.');
        }
        $this->path = $directory.'/subscription-'.hash('sha256', $runId).'.json';
        $this->evidence = ['run_hash' => hash('sha256', $runId), 'status' => 'prepared'];
        $this->persist();
    }

    public function completed(): void
    {
        $this->evidence['status'] = 'completed';
        $this->persist();
    }

    public function cleanup(string $status): void
    {
        if (! in_array($status, ['deleted', 'failed'], true)) {
            throw new RuntimeException('The subscription cleanup evidence status is invalid.');
        }
        $this->evidence['cleanup_status'] = $status;
        $this->persist();
    }

    public function requestCounts(int $operations, int $cleanup): void
    {
        $this->evidence['operation_request_attempts'] = $operations;
        $this->evidence['cleanup_request_attempts'] = $cleanup;
        $this->persist();
    }

    public function timings(
        float $workerReady,
        float $firstArrival,
        float $lastArrival,
        float $lastCompletion,
        float $arrivalWindow,
        float $processing,
        float $queueDrain,
        float $cleanup,
    ): void {
        $this->evidence['worker_ready_seconds'] = $workerReady;
        $this->evidence['first_arrival_seconds'] = $firstArrival;
        $this->evidence['last_arrival_seconds'] = $lastArrival;
        $this->evidence['last_completion_seconds'] = $lastCompletion;
        $this->evidence['arrival_window_seconds'] = $arrivalWindow;
        $this->evidence['processing_seconds'] = $processing;
        $this->evidence['queue_drain_seconds'] = $queueDrain;
        $this->evidence['cleanup_seconds'] = $cleanup;
        $this->persist();
    }

    /** @param array<string, array{operation: int, cleanup: int}> $attempts */
    public function consumerRequestCounts(array $attempts): void
    {
        foreach ($attempts as $consumer => $counts) {
            if (preg_match('/^(parent|worker-[0-9]+)$/D', $consumer) !== 1
                || $counts['operation'] < 0 || $counts['cleanup'] < 0) {
                throw new RuntimeException('Subscription request consumer evidence is invalid.');
            }
        }
        ksort($attempts);
        $this->evidence['request_attempts_by_consumer'] = $attempts;
        $this->persist();
    }

    private function persist(): void
    {
        $payload = json_encode($this->evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
        if (file_put_contents($this->path, $payload, LOCK_EX) !== strlen($payload) || ! chmod($this->path, 0600)) {
            throw new RuntimeException('The subscription evidence could not be stored securely.');
        }
    }
}
