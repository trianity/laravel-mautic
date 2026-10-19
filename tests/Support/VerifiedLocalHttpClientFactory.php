<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Tests\Support;

use GuzzleHttp\Client;
use Psr\Http\Client\ClientInterface;
use Trianity\LaravelMautic\Configuration\ConnectionConfig;
use Trianity\LaravelMautic\Contracts\HttpClientFactory;

final readonly class VerifiedLocalHttpClientFactory implements HttpClientFactory
{
    public function __construct(private string $caBundle) {}

    public function make(ConnectionConfig $config): ClientInterface
    {
        return new Client([
            'timeout' => $config->timeout,
            'connect_timeout' => $config->connectTimeout,
            'verify' => $this->caBundle,
            'allow_redirects' => false,
            'http_errors' => false,
        ]);
    }
}
