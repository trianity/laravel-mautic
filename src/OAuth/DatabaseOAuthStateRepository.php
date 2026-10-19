<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\OAuth;

use Illuminate\Database\ConnectionResolverInterface;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Contracts\OAuthStateRepository;

final readonly class DatabaseOAuthStateRepository implements OAuthStateRepository
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private Clock $clock,
        private ?string $databaseConnection = null,
        private string $table = 'mautic_oauth_states',
    ) {}

    public function issue(string $connection, string $authorizationContext, int $ttlSeconds): string
    {
        if ($authorizationContext === '' || $ttlSeconds <= 0) {
            throw new \InvalidArgumentException('OAuth authorization context and a positive state TTL are required.');
        }

        $state = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->connections->connection($this->databaseConnection)->table($this->table)->insert([
            'state_hash' => hash('sha256', $state),
            'connection' => $connection,
            'context_hash' => hash('sha256', $authorizationContext),
            'expires_at' => $this->clock->now() + $ttlSeconds,
            'created_at' => date('Y-m-d H:i:s', $this->clock->now()),
        ]);

        return $state;
    }

    public function consume(string $connection, string $state, string $authorizationContext): bool
    {
        if ($state === '' || $authorizationContext === '') {
            return false;
        }

        return $this->connections->connection($this->databaseConnection)->table($this->table)
            ->where('state_hash', hash('sha256', $state))
            ->where('connection', $connection)
            ->where('context_hash', hash('sha256', $authorizationContext))
            ->where('expires_at', '>', $this->clock->now())
            ->delete() === 1;
    }
}
