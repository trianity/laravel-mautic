<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Tests\Support;

use InvalidArgumentException;
use RuntimeException;

final class OAuthAcceptanceRequestBudget
{
    public const OPERATION_LIMIT = 16;

    public const CLEANUP_LIMIT = 5;

    private int $operationAttempts = 0;

    private int $cleanupAttempts = 0;

    private bool $cleanup = false;

    private bool $operationRejected = false;

    private bool $cleanupRejected = false;

    public function __construct(
        private readonly int $operationLimit = self::OPERATION_LIMIT,
        private readonly int $cleanupLimit = self::CLEANUP_LIMIT,
    ) {
        if ($operationLimit < 1 || $cleanupLimit < 1) {
            throw new InvalidArgumentException('OAuth acceptance request limits must be positive.');
        }
    }

    public function consume(): void
    {
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
        $this->cleanup = true;
    }

    public function operationAttempts(): int
    {
        return $this->operationAttempts;
    }

    public function cleanupAttempts(): int
    {
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
}
