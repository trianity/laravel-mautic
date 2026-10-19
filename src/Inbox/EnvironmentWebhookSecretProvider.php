<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Inbox;

use Illuminate\Contracts\Config\Repository;
use Trianity\LaravelMautic\Contracts\WebhookSecretProvider;
use Trianity\LaravelMautic\Exceptions\WebhookIngressException;

final readonly class EnvironmentWebhookSecretProvider implements WebhookSecretProvider
{
    public function __construct(private Repository $config) {}

    #[\Override]
    public function secret(string $connection): string
    {
        $connections = $this->config->get('mautic.connections');
        if (! is_array($connections) || ! array_key_exists($connection, $connections)) {
            throw new WebhookIngressException(WebhookIngressFailureKind::UnknownConnection);
        }

        $values = $connections[$connection];
        $webhook = is_array($values) ? ($values['webhook'] ?? null) : null;
        $environment = is_array($webhook) ? ($webhook['secret_env'] ?? null) : null;
        if (! is_string($environment) || trim($environment) === '') {
            throw new WebhookIngressException(WebhookIngressFailureKind::SecretUnavailable);
        }

        $secret = getenv($environment);
        if (! is_string($secret) || $secret === '') {
            throw new WebhookIngressException(WebhookIngressFailureKind::SecretUnavailable);
        }

        return $secret;
    }
}
