<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Contracts;

interface OAuthStateRepository
{
    public function issue(string $connection, string $authorizationContext, int $ttlSeconds): string;

    public function consume(string $connection, string $state, string $authorizationContext): bool;
}
