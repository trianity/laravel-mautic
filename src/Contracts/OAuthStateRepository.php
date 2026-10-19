<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Contracts;

interface OAuthStateRepository
{
    public function issue(string $connection, int $ttlSeconds): string;

    public function consume(string $connection, string $state): bool;
}
