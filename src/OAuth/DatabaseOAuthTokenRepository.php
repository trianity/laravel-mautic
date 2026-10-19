<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\OAuth;

use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\ConnectionResolverInterface;
use RuntimeException;
use Trianity\LaravelMautic\Contracts\OAuthTokenRepository;
use Trianity\LaravelMautic\Data\OAuthToken;

final readonly class DatabaseOAuthTokenRepository implements OAuthTokenRepository
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private StringEncrypter $encrypter,
        private ?string $databaseConnection = null,
        private string $table = 'mautic_oauth_tokens',
    ) {}

    public function get(string $connection): ?OAuthToken
    {
        $row = $this->connections->connection($this->databaseConnection)
            ->table($this->table)->where('connection', $connection)->first();

        if ($row === null || ! is_string($row->token_payload ?? null)) {
            return null;
        }

        $payload = json_decode($this->encrypter->decryptString($row->token_payload), true);
        if (! is_array($payload)
            || ! is_string($payload['access_token'] ?? null)
            || ! is_string($payload['refresh_token'] ?? null)
            || ! is_int($payload['expires_at'] ?? null)
            || ! is_string($payload['token_type'] ?? null)) {
            throw new RuntimeException('Stored OAuth token data is invalid.');
        }

        return new OAuthToken(
            $payload['access_token'],
            $payload['refresh_token'],
            $payload['expires_at'],
            $payload['token_type'],
        );
    }

    public function save(string $connection, OAuthToken $token): void
    {
        $payload = json_encode([
            'access_token' => $token->accessToken,
            'refresh_token' => $token->refreshToken,
            'expires_at' => $token->expiresAt,
            'token_type' => $token->tokenType,
        ], JSON_THROW_ON_ERROR);
        $encrypted = $this->encrypter->encryptString($payload);
        $now = date('Y-m-d H:i:s');
        $database = $this->connections->connection($this->databaseConnection);

        $database->transaction(function () use ($database, $connection, $encrypted, $token, $now): void {
            $database->table($this->table)->upsert([[
                'connection' => $connection,
                'token_payload' => $encrypted,
                'expires_at' => $token->expiresAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['connection'], ['token_payload', 'expires_at', 'updated_at']);
        });
    }
}
