<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Tests\Support;

use RuntimeException;

final class OAuthFixtureEvidence
{
    private readonly string $path;

    /** @var array<string, int|string|null> */
    private array $evidence;

    public function __construct(string $runId, string $email, ?string $storageDirectory = null)
    {
        $directory = $storageDirectory ?? dirname(__DIR__).'/.oauth-acceptance';
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            throw new RuntimeException('The local OAuth fixture evidence directory could not be created.');
        }
        if (! chmod($directory, 0700)) {
            throw new RuntimeException('The local OAuth fixture evidence directory could not be restricted.');
        }

        $this->path = $directory.'/fixture-'.$runId.'.json';
        $this->evidence = [
            'run_id' => $runId,
            'email' => $email,
            'remote_id' => null,
            'status' => 'prepared',
        ];
        $this->persist();
    }

    public function created(int $remoteId): void
    {
        $this->evidence['remote_id'] = $remoteId;
        $this->evidence['status'] = 'created';
        $this->persist();
    }

    public function ambiguousCreate(): void
    {
        $this->evidence['status'] = 'ambiguous_create';
        $this->persist();
    }

    public function deleted(): void
    {
        $this->evidence['status'] = 'deleted';
        $this->persist();
    }

    public function cleanupFailed(): void
    {
        $this->evidence['status'] = 'cleanup_failed';
        $this->persist();
    }

    public function membershipCleanup(string $outcome): void
    {
        $this->evidence['membership_cleanup'] = $outcome;
        $this->persist();
    }

    public function requestCounts(int $operationAttempts, int $cleanupAttempts): void
    {
        $this->evidence['operation_request_attempts'] = $operationAttempts;
        $this->evidence['cleanup_request_attempts'] = $cleanupAttempts;
        $this->persist();
    }

    public function path(): string
    {
        return $this->path;
    }

    private function persist(): void
    {
        $payload = json_encode($this->evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
        if (file_put_contents($this->path, $payload, LOCK_EX) !== strlen($payload)
            || ! chmod($this->path, 0600)) {
            throw new RuntimeException('The local OAuth fixture evidence could not be stored securely.');
        }
    }
}
