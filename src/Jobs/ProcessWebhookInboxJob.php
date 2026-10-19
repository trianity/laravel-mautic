<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Trianity\LaravelMautic\Inbox\WebhookInboxProcessor;

final class ProcessWebhookInboxJob implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(public readonly int $eventId) {}

    public function handle(WebhookInboxProcessor $processor): void
    {
        $processor->process($this->eventId);
    }
}
