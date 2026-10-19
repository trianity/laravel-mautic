<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Contracts;

use Trianity\LaravelMautic\Data\OAuthToken;
use Trianity\LaravelMautic\Data\OAuthTokenRecord;

interface OAuthTokenRepository
{
    public function get(string $connection): ?OAuthToken;

    public function record(string $connection): ?OAuthTokenRecord;

    public function persist(string $connection, OAuthToken $token, ?int $expectedGeneration): bool;
}
