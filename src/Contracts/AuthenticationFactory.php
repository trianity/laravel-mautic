<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Contracts;

use Mautic\Auth\AuthInterface;
use Psr\Http\Client\ClientInterface;
use Trianity\LaravelMautic\Configuration\ConnectionConfig;

interface AuthenticationFactory
{
    public function make(ConnectionConfig $config, ClientInterface $client): AuthInterface;
}
