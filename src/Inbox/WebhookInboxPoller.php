<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Inbox;

final readonly class WebhookInboxPoller
{
    public function __construct(
        private DatabaseWebhookInboxStore $store,
        private WebhookInboxDispatcher $dispatcher,
    ) {}

    public function recover(int $limit = 100): int
    {
        $dispatched = 0;
        foreach ($this->store->recoverableEventIds($limit) as $eventId) {
            if ($this->dispatcher->dispatch($eventId)) {
                $dispatched++;
            }
        }

        return $dispatched;
    }
}
