<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Support;

use Mautic\Auth\AuthInterface;
use Mautic\Auth\BasicAuth;
use Psr\Http\Client\ClientInterface;
use Trianity\LaravelMautic\Configuration\ConnectionConfig;
use Trianity\LaravelMautic\Contracts\AuthenticationFactory;
use Trianity\LaravelMautic\OAuth\ManagedOAuthAuth;
use Trianity\LaravelMautic\OAuth\OAuthTokenManager;

final readonly class ApiLibraryAuthenticationFactory implements AuthenticationFactory
{
    public function __construct(private OAuthTokenManager $tokens) {}

    public function make(ConnectionConfig $config, ClientInterface $client): AuthInterface
    {
        if ($config->auth === 'oauth2') {
            return new ManagedOAuthAuth($config->name, $client, $this->tokens);
        }

        $auth = new BasicAuth($client);
        $auth->setup($config->username, $config->password);

        return $auth;
    }
}
