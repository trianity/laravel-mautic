<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Contracts;

use Trianity\LaravelMautic\Configuration\ConnectionConfig;
use Trianity\LaravelMautic\MauticConnection;

interface ConnectionFactory
{
    public function make(ConnectionConfig $config): MauticConnection;
}
