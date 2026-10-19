<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Inbox;

use Illuminate\Contracts\Queue\Queue;
use Throwable;
use Trianity\LaravelMautic\Jobs\ProcessWebhookInboxJob;

final readonly class WebhookInboxDispatcher
{
    public function __construct(private Queue $queue, private ?string $queueName = null) {}

    public function dispatch(int $eventId): bool
    {
        try {
            $this->queue->push(new ProcessWebhookInboxJob($eventId), queue: $this->queueName);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
