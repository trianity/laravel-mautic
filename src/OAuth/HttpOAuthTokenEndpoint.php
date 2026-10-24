<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\OAuth;

use GuzzleHttp\Psr7\Request;
use Illuminate\Contracts\Config\Repository;
use JsonException;
use Throwable;
use Trianity\LaravelMautic\Configuration\ConnectionConfig;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Contracts\HttpClientFactory;
use Trianity\LaravelMautic\Contracts\OAuthClientCredentialsProvider;
use Trianity\LaravelMautic\Contracts\OAuthTokenEndpoint;
use Trianity\LaravelMautic\Data\OAuthToken;
use Trianity\LaravelMautic\Exceptions\InvalidConfiguration;
use Trianity\LaravelMautic\Exceptions\OAuthReauthorizationRequired;
use Trianity\LaravelMautic\Exceptions\OAuthTokenRequestFailed;

final readonly class HttpOAuthTokenEndpoint implements OAuthTokenEndpoint
{
    public function __construct(
        private Repository $config,
        private HttpClientFactory $clients,
        private OAuthClientCredentialsProvider $credentials,
        private Clock $clock,
        private ?OAuthCallbackUrlResolver $callbackUrls = null,
    ) {}

    public function exchange(string $connection, string $code): OAuthToken
    {
        $connectionConfig = $this->connection($connection);
        $credentials = $this->credentials->forConnection($connection);

        return $this->request($connectionConfig, [
            'grant_type' => 'authorization_code',
            'client_id' => $credentials->clientId,
            'client_secret' => $credentials->clientSecret,
            'redirect_uri' => ($this->callbackUrls ?? new OAuthCallbackUrlResolver($this->config))->resolve($connection),
            'code' => $code,
        ], null);
    }

    public function refresh(string $connection, OAuthToken $token): OAuthToken
    {
        $connectionConfig = $this->connection($connection);
        $credentials = $this->credentials->forConnection($connection);

        return $this->request($connectionConfig, [
            'grant_type' => 'refresh_token',
            'client_id' => $credentials->clientId,
            'client_secret' => $credentials->clientSecret,
            'refresh_token' => $token->refreshToken,
        ], $token->refreshToken);
    }

    /** @param array<string, string> $parameters */
    private function request(
        ConnectionConfig $config,
        array $parameters,
        ?string $fallbackRefreshToken,
    ): OAuthToken {
        $request = new Request(
            'POST',
            $config->url.'/oauth/v2/token',
            ['Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query($parameters, '', '&', PHP_QUERY_RFC3986),
        );

        try {
            $response = $this->clients->make($config)->sendRequest($request);
        } catch (Throwable) {
            throw new OAuthTokenRequestFailed($config->name, OAuthTokenFailureKind::Network);
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $retryAfter = $this->retryAfterSeconds($response->getHeaderLine('Retry-After'));
            if ($status === 429) {
                throw new OAuthTokenRequestFailed(
                    $config->name,
                    OAuthTokenFailureKind::Throttled,
                    $status,
                    $retryAfter,
                );
            }
            if ($status >= 500) {
                throw new OAuthTokenRequestFailed($config->name, OAuthTokenFailureKind::RemoteServer, $status);
            }

            $error = $this->oauthError((string) $response->getBody());
            if ($error === 'invalid_grant') {
                throw new OAuthReauthorizationRequired($config->name, $status);
            }
            if ($error === 'invalid_client') {
                throw new OAuthTokenRequestFailed($config->name, OAuthTokenFailureKind::InvalidClient, $status);
            }
            if ($error === null) {
                throw new OAuthTokenRequestFailed($config->name, OAuthTokenFailureKind::MalformedResponse, $status);
            }

            throw new OAuthTokenRequestFailed($config->name, OAuthTokenFailureKind::ClientRequest, $status);
        }

        try {
            $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new OAuthTokenRequestFailed($config->name, OAuthTokenFailureKind::MalformedResponse, $status);
        }

        $accessToken = is_array($payload) ? ($payload['access_token'] ?? null) : null;
        $refreshToken = is_array($payload) ? ($payload['refresh_token'] ?? $fallbackRefreshToken) : null;
        $expiresIn = is_array($payload) ? ($payload['expires_in'] ?? null) : null;
        $tokenType = is_array($payload) ? ($payload['token_type'] ?? 'bearer') : null;

        if (! is_string($accessToken) || $accessToken === ''
            || ! is_string($refreshToken) || $refreshToken === ''
            || (! is_int($expiresIn) && ! (is_string($expiresIn) && ctype_digit($expiresIn)))
            || (int) $expiresIn <= 0 || ! is_string($tokenType)) {
            throw new OAuthTokenRequestFailed($config->name, OAuthTokenFailureKind::MalformedResponse, $status);
        }

        return new OAuthToken($accessToken, $refreshToken, $this->clock->now() + (int) $expiresIn, $tokenType);
    }

    private function oauthError(string $body): ?string
    {
        try {
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $error = is_array($payload) ? ($payload['error'] ?? null) : null;

        return is_string($error) && $error !== '' ? $error : null;
    }

    private function retryAfterSeconds(string $header): ?int
    {
        return ctype_digit($header) ? (int) $header : null;
    }

    private function connection(string $name): ConnectionConfig
    {
        $values = $this->config->get('mautic.connections.'.$name);
        if (! is_array($values)) {
            throw new InvalidConfiguration('The requested Mautic connection is not configured.');
        }

        return ConnectionConfig::fromArray($name, $values);
    }
}
