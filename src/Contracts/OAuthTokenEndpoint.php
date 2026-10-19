<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Contracts;

use Trianity\LaravelMautic\Data\OAuthToken;

interface OAuthTokenEndpoint
{
    public function exchange(string $connection, string $code): OAuthToken;

    public function refresh(string $connection, OAuthToken $token): OAuthToken;
}
