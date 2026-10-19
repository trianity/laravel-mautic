<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Contracts;

interface WebhookSecretProvider
{
    public function secret(string $connection): string;
}
