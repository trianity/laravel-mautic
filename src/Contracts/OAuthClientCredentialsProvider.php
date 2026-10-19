<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Contracts;

use Trianity\LaravelMautic\Data\OAuthClientCredentials;

interface OAuthClientCredentialsProvider
{
    public function forConnection(string $connection): OAuthClientCredentials;
}
