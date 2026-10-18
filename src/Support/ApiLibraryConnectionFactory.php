<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Support;

use Mautic\Auth\BasicAuth;
use Trianity\LaravelMautic\Configuration\ConnectionConfig;
use Trianity\LaravelMautic\Contracts\ConnectionFactory;
use Trianity\LaravelMautic\Contracts\HttpClientFactory;
use Trianity\LaravelMautic\MauticConnection;

final readonly class ApiLibraryConnectionFactory implements ConnectionFactory
{
    public function __construct(private HttpClientFactory $httpClients) {}

    public function make(ConnectionConfig $config): MauticConnection
    {
        $auth = new BasicAuth($this->httpClients->make($config));
        $auth->setup($config->username, $config->password);

        return new MauticConnection($config->name, $config->apiUrl(), $auth);
    }
}
