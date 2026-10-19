<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Trianity\LaravelMautic\Outbox\OutboxProcessor;

final class ProcessContactOutboxJob implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(public readonly string $operationId) {}

    public function handle(OutboxProcessor $processor): void
    {
        $processor->process($this->operationId);
    }
}
