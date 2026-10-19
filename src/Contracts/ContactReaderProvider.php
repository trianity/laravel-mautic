<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Contracts;

interface ContactReaderProvider
{
    public function forConnection(string $connection): ContactReader;
}
