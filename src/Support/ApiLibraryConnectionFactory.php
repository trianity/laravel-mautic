<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Support;

use Mautic\Auth\BasicAuth;
use Trianity\LaravelMautic\Configuration\ConnectionConfig;
use Trianity\LaravelMautic\Contracts\AuthenticationFactory;
use Trianity\LaravelMautic\Contracts\ConnectionFactory;
use Trianity\LaravelMautic\Contracts\HttpClientFactory;
use Trianity\LaravelMautic\MauticConnection;

final readonly class ApiLibraryConnectionFactory implements ConnectionFactory
{
    public function __construct(
        private HttpClientFactory $httpClients,
        private ?AuthenticationFactory $authentication = null,
    ) {}

    public function make(ConnectionConfig $config): MauticConnection
    {
        $client = $this->httpClients->make($config);
        if ($this->authentication === null) {
            $auth = new BasicAuth($client);
            $auth->setup($config->username, $config->password);
        } else {
            $auth = $this->authentication->make($config, $client);
        }

        return new MauticConnection($config->name, $config->apiUrl(), $auth);
    }
}
