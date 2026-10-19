<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Inbox;

final readonly class ParsedWebhookEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public int $ordinal,
        public string $type,
        public array $payload,
        public ?string $semanticId,
        public ?int $remoteId,
        public ?int $occurredAt,
    ) {}
}
