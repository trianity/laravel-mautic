<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Outbox;

use Illuminate\Contracts\Queue\Queue;
use Throwable;
use Trianity\LaravelMautic\Jobs\ProcessContactOutboxJob;

final readonly class OutboxDispatcher
{
    public function __construct(
        private Queue $queue,
        private DatabaseOutboxStore $store,
        private ?string $queueName = null,
    ) {}

    public function dispatch(string $operationId): bool
    {
        try {
            $this->queue->push(new ProcessContactOutboxJob($operationId), queue: $this->queueName);
            $this->store->markDispatched($operationId);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
