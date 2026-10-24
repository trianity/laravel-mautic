<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\OAuth;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Env;
use Trianity\LaravelMautic\Contracts\OAuthClientCredentialsProvider;
use Trianity\LaravelMautic\Data\OAuthClientCredentials;
use Trianity\LaravelMautic\Exceptions\InvalidConfiguration;

final readonly class EnvironmentOAuthClientCredentialsProvider implements OAuthClientCredentialsProvider
{
    public function __construct(private Repository $config) {}

    public function forConnection(string $connection): OAuthClientCredentials
    {
        $clientId = $this->config->get("mautic.connections.{$connection}.oauth.client_id");
        $secretVariable = $this->config->get("mautic.connections.{$connection}.oauth.client_secret_env");
        $secret = is_string($secretVariable) ? Env::get($secretVariable) : null;
        if ((! is_string($secret) || trim($secret) === '') && is_string($secretVariable)) {
            $secret = getenv($secretVariable);
        }

        if (! is_string($clientId) || trim($clientId) === ''
            || ! is_string($secretVariable) || trim($secretVariable) === ''
            || ! is_string($secret) || trim($secret) === '') {
            throw new InvalidConfiguration('OAuth2 client credentials are unavailable for the requested connection.');
        }

        return new OAuthClientCredentials($clientId, $secret);
    }
}
