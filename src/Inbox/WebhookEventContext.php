<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Inbox;

final readonly class WebhookEventContext
{
    public function __construct(
        public int $eventId,
        public string $receiptId,
        public string $connection,
        public string $eventType,
        public ?int $occurredAt,
    ) {}
}
