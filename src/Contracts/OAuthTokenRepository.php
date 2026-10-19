<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Contracts;

use Trianity\LaravelMautic\Data\OAuthToken;

interface OAuthTokenRepository
{
    public function get(string $connection): ?OAuthToken;

    public function save(string $connection, OAuthToken $token): void;
}
