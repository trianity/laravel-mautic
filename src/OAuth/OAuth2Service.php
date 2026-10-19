<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\OAuth;

use Illuminate\Contracts\Config\Repository;
use Trianity\LaravelMautic\Configuration\ConnectionConfig;
use Trianity\LaravelMautic\Contracts\OAuthStateRepository;
use Trianity\LaravelMautic\Contracts\OAuthTokenEndpoint;
use Trianity\LaravelMautic\Contracts\OAuthTokenRepository;
use Trianity\LaravelMautic\Data\OAuthToken;
use Trianity\LaravelMautic\Exceptions\InvalidConfiguration;
use Trianity\LaravelMautic\Exceptions\InvalidOAuthState;

final readonly class OAuth2Service
{
    public function __construct(
        private Repository $config,
        private OAuthStateRepository $states,
        private OAuthTokenRepository $tokens,
        private OAuthTokenEndpoint $endpoint,
        private int $stateTtlSeconds = 600,
    ) {}

    public function authorizationUrl(string $connection): string
    {
        $settings = $this->settings($connection);
        $state = $this->states->issue($connection, $this->stateTtlSeconds);
        $query = http_build_query([
            'grant_type' => 'authorization_code',
            'client_id' => $settings['client_id'],
            'redirect_uri' => $settings['redirect_uri'],
            'response_type' => 'code',
            'state' => $state,
            'scope' => implode(',', $settings['scopes']),
        ], '', '&', PHP_QUERY_RFC3986);

        return rtrim($settings['url'], '/').'/oauth/v2/authorize?'.$query;
    }

    public function complete(string $connection, string $state, string $code): OAuthToken
    {
        if (! $this->states->consume($connection, $state)) {
            throw new InvalidOAuthState;
        }
        if ($code === '') {
            throw new InvalidOAuthState;
        }

        $token = $this->endpoint->exchange($connection, $code);
        $this->tokens->save($connection, $token);

        return $token;
    }

    /** @return array{url: string, client_id: string, redirect_uri: string, scopes: list<string>} */
    private function settings(string $connection): array
    {
        $values = $this->config->get('mautic.connections.'.$connection);
        if (! is_array($values)) {
            throw new InvalidConfiguration('The requested Mautic connection is not configured.');
        }

        $connectionConfig = ConnectionConfig::fromArray($connection, $values);
        if ($connectionConfig->auth !== 'oauth2') {
            throw new InvalidConfiguration('The requested Mautic connection does not use OAuth2.');
        }

        $oauth = $values['oauth'] ?? null;
        $url = $connectionConfig->url;
        $clientId = is_array($oauth) ? ($oauth['client_id'] ?? null) : null;
        $redirectUri = is_array($oauth) ? ($oauth['redirect_uri'] ?? null) : null;
        $scopes = is_array($oauth) ? ($oauth['scopes'] ?? []) : [];

        if (! is_string($clientId) || $clientId === ''
            || ! is_string($redirectUri) || filter_var($redirectUri, FILTER_VALIDATE_URL) === false
            || ! is_array($scopes) || array_any($scopes, static fn (mixed $scope): bool => ! is_string($scope))) {
            throw new InvalidConfiguration('The OAuth2 connection configuration is incomplete.');
        }

        /** @var list<string> $scopes */
        return [
            'url' => $url,
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scopes' => $scopes,
        ];
    }
}
