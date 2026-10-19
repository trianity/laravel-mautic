<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Inbox;

final readonly class StoredWebhookEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public int $id,
        public string $receiptId,
        public string $connection,
        public string $eventType,
        public ?string $semanticHash,
        public ?int $remoteId,
        public ?int $occurredAt,
        public array $payload,
        public int $attempts,
    ) {}
}
