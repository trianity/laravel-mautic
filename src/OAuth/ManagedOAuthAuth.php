<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\OAuth;

use Mautic\Auth\AuthInterface;
use Mautic\Auth\OAuth;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use Trianity\LaravelMautic\Contracts\OAuthAuthenticationFailureProvider;
use Trianity\LaravelMautic\Exceptions\OAuthAuthenticationException;
use Trianity\LaravelMautic\Exceptions\OAuthPreRequestFailed;

final class ManagedOAuthAuth implements AuthInterface, OAuthAuthenticationFailureProvider
{
    private ?OAuth $delegate = null;

    private ?OAuthAuthenticationException $authenticationFailure = null;

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
        $this->authenticationFailure = null;
        try {
            $token = $this->tokens->validToken($this->connection);
        } catch (OAuthAuthenticationException $exception) {
            $this->authenticationFailure = $exception;

            return ['errors' => [['code' => 0, 'message' => 'OAuth authentication failed before API request.']]];
        } catch (Throwable) {
            $this->authenticationFailure = new OAuthPreRequestFailed($this->connection);

            return ['errors' => [['code' => 0, 'message' => 'OAuth authentication failed before API request.']]];
        }
        $this->delegate = new OAuth($this->client);
        $this->delegate->setup(accessToken: $token->accessToken, accessTokenExpires: (string) $token->expiresAt);

        /** @var array<mixed>|string $response */
        $response = $this->delegate->makeRequest($url, $parameters, $method, $settings);

        return $response;
    }

    public function pullAuthenticationFailure(): ?OAuthAuthenticationException
    {
        $failure = $this->authenticationFailure;
        $this->authenticationFailure = null;

        return $failure;
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
