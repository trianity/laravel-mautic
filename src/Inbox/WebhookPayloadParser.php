<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Inbox;

use DateTimeImmutable;
use JsonException;
use Trianity\LaravelMautic\Exceptions\WebhookIngressException;

final class WebhookPayloadParser
{
    /** @return list<ParsedWebhookEvent> */
    public function parse(string $rawBody): array
    {
        try {
            $decoded = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new WebhookIngressException(WebhookIngressFailureKind::InvalidJson);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new WebhookIngressException(WebhookIngressFailureKind::InvalidPayload);
        }

        $events = [];
        $ordinal = 0;
        foreach ($decoded as $type => $items) {
            if (! is_string($type) || trim($type) === '' || strlen($type) > 191 || ! is_array($items) || ! array_is_list($items)) {
                throw new WebhookIngressException(WebhookIngressFailureKind::InvalidPayload);
            }
            foreach ($items as $item) {
                if (! is_array($item) || array_is_list($item)) {
                    throw new WebhookIngressException(WebhookIngressFailureKind::InvalidPayload);
                }
                $events[] = new ParsedWebhookEvent(
                    $ordinal++,
                    $type,
                    $item,
                    $this->semanticId($item),
                    $this->remoteId($item),
                    $this->occurredAt($item),
                );
            }
        }

        if ($events === []) {
            throw new WebhookIngressException(WebhookIngressFailureKind::InvalidPayload);
        }

        return $events;
    }

    /** @param array<string, mixed> $payload */
    private function semanticId(array $payload): ?string
    {
        foreach (['eventId', 'event_id'] as $key) {
            $value = $payload[$key] ?? null;
            if ((is_string($value) || is_int($value)) && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $payload */
    private function remoteId(array $payload): ?int
    {
        foreach (['contact', 'lead'] as $key) {
            $contact = $payload[$key] ?? null;
            $id = is_array($contact) ? ($contact['id'] ?? null) : null;
            if (is_int($id) && $id > 0) {
                return $id;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $payload */
    private function occurredAt(array $payload): ?int
    {
        $timestamp = $payload['timestamp'] ?? null;
        if (! is_string($timestamp) || trim($timestamp) === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($timestamp))->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }
}
