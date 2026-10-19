<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Tests\Support;

use Throwable;
use Trianity\LaravelMautic\Inbox\WebhookPayloadParser;

final readonly class LiveWebhookCaptureMatcher
{
    public function __construct(private WebhookPayloadParser $parser = new WebhookPayloadParser) {}

    public function containsExpectedUpdate(
        string $jsonLines,
        string $secret,
        int $remoteId,
        string $email,
        string $field,
        mixed $expectedValue,
    ): bool {
        $lastNewline = strrpos($jsonLines, "\n");
        if ($lastNewline === false) {
            return false;
        }
        foreach (explode("\n", substr($jsonLines, 0, $lastNewline)) as $line) {
            if ($line === '' || $this->matches($line, $secret, $remoteId, $email, $field, $expectedValue) === false) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function matches(
        string $line,
        string $secret,
        int $remoteId,
        string $email,
        string $field,
        mixed $expectedValue,
    ): bool {
        try {
            $capture = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
            $body = is_array($capture) && is_string($capture['body_base64'] ?? null)
                ? base64_decode($capture['body_base64'], true)
                : false;
            $signature = is_array($capture) ? ($capture['signature'] ?? null) : null;
            if (! is_string($body) || ! is_string($signature)
                || ! hash_equals(base64_encode(hash_hmac('sha256', $body, $secret, true)), $signature)) {
                return false;
            }
            foreach ($this->parser->parse($body) as $event) {
                if ($event->type !== 'mautic.lead_post_save_update' || $event->remoteId !== $remoteId) {
                    continue;
                }
                $contact = $event->payload['contact'] ?? $event->payload['lead'] ?? null;
                $fields = is_array($contact) ? ($contact['fields']['all'] ?? null) : null;
                if (is_array($fields)
                    && ($fields['email'] ?? null) === $email
                    && array_key_exists($field, $fields)
                    && $fields[$field] === $expectedValue) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }
}
