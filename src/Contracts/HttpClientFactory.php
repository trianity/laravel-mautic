<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Contracts;

use Psr\Http\Client\ClientInterface;
use Trianity\LaravelMautic\Configuration\ConnectionConfig;

interface HttpClientFactory
{
    public function make(ConnectionConfig $config): ClientInterface;
}
