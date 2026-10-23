<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Subscription;

use Illuminate\Contracts\Queue\Queue;
use Throwable;
use Trianity\LaravelMautic\Jobs\ProcessSubscriptionJob;

final readonly class SubscriptionDispatcher
{
    public function __construct(private Queue $queue, private ?string $queueName = null) {}

    public function dispatch(string $operationId): bool
    {
        try {
            $this->queue->push(new ProcessSubscriptionJob($operationId), queue: $this->queueName);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
