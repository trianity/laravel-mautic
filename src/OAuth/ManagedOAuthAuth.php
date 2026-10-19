<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\OAuth;

use Mautic\Auth\AuthInterface;
use Mautic\Auth\OAuth;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;

final class ManagedOAuthAuth implements AuthInterface
{
    private ?OAuth $delegate = null;

    public function __construct(
        private readonly string $connection,
        private readonly ClientInterface $client,
        private readonly OAuthTokenManager $tokens,
    ) {}

    public function isAuthorized(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $settings
     * @return array<mixed>|string
     */
    public function makeRequest($url, array $parameters = [], $method = 'GET', array $settings = []): array|string
    {
        $token = $this->tokens->validToken($this->connection);
        $this->delegate = new OAuth($this->client);
        $this->delegate->setup(accessToken: $token->accessToken, accessTokenExpires: (string) $token->expiresAt);

        /** @var array<mixed>|string $response */
        $response = $this->delegate->makeRequest($url, $parameters, $method, $settings);

        return $response;
    }

    public function getResponse(): ResponseInterface
    {
        if ($this->delegate === null) {
            throw new \LogicException('No OAuth request has been made.');
        }

        return $this->delegate->getResponse();
    }

    /** @return array<string, string> */
    public function getResponseHeaders(): array
    {
        if ($this->delegate === null) {
            return [];
        }

        /** @var array<string, string> */
        return $this->delegate->getResponseHeaders();
    }
}
