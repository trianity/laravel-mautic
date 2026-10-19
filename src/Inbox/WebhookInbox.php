<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Inbox;

use Illuminate\Contracts\Config\Repository;
use SensitiveParameter;
use Throwable;
use Trianity\LaravelMautic\Contracts\WebhookSecretProvider;
use Trianity\LaravelMautic\Exceptions\WebhookIngressException;

final readonly class WebhookInbox
{
    public function __construct(
        private Repository $config,
        private WebhookSecretProvider $secrets,
        private WebhookPayloadParser $parser,
        private DatabaseWebhookInboxStore $store,
        private WebhookInboxDispatcher $dispatcher,
    ) {}

    public function receive(string $connection, string $rawBody, #[SensitiveParameter] ?string $signature): WebhookReceipt
    {
        $maximumBytes = (int) $this->config->get('mautic.inbox.maximum_body_bytes', 1_048_576);
        if ($maximumBytes < 1 || strlen($rawBody) > $maximumBytes) {
            throw new WebhookIngressException(WebhookIngressFailureKind::BodyTooLarge);
        }
        if ($signature === null || $signature === '') {
            throw new WebhookIngressException(WebhookIngressFailureKind::MissingSignature);
        }

        $secret = $this->secrets->secret($connection);
        $expected = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
        if (! hash_equals($expected, $signature)) {
            throw new WebhookIngressException(WebhookIngressFailureKind::InvalidSignature);
        }

        $events = $this->parser->parse($rawBody);
        $receiptId = bin2hex(random_bytes(32));
        try {
            $this->store->record($receiptId, $connection, $rawBody, $events);
        } catch (Throwable $exception) {
            throw new WebhookIngressException(WebhookIngressFailureKind::Persistence, previous: $exception);
        }

        $dispatched = true;
        foreach ($this->store->eventIdsForReceipt($receiptId) as $eventId) {
            $dispatched = $this->dispatcher->dispatch($eventId) && $dispatched;
        }

        return new WebhookReceipt($receiptId, count($events), $dispatched);
    }
}
