<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Tests\Support;

use RuntimeException;

final class OAuthSubscriptionEvidence
{
    private readonly string $path;

    /** @var array<string, int|string> */
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

    private function persist(): void
    {
        $payload = json_encode($this->evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
        if (file_put_contents($this->path, $payload, LOCK_EX) !== strlen($payload) || ! chmod($this->path, 0600)) {
            throw new RuntimeException('The subscription evidence could not be stored securely.');
        }
    }
}
