<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Contracts;

use Trianity\LaravelMautic\Identity\ContactDiscoveryResult;

interface ContactDiscovery
{
    public function exactEmail(string $email): ContactDiscoveryResult;
}
