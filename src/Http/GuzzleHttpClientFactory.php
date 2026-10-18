<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Http;

use GuzzleHttp\Client;
use Psr\Http\Client\ClientInterface;
use Trianity\LaravelMautic\Configuration\ConnectionConfig;
use Trianity\LaravelMautic\Contracts\HttpClientFactory;

final class GuzzleHttpClientFactory implements HttpClientFactory
{
    public function make(ConnectionConfig $config): ClientInterface
    {
        return new Client([
            'timeout' => $config->timeout,
            'connect_timeout' => $config->connectTimeout,
            'verify' => true,
            'allow_redirects' => false,
            'http_errors' => false,
        ]);
    }
}
