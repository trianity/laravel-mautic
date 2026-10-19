<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Inbox;

final readonly class WebhookReceipt
{
    public function __construct(
        public string $receiptId,
        public int $eventCount,
        public bool $dispatched,
    ) {}
}
