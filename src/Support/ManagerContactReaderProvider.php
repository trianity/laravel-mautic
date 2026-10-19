<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Support;

use Trianity\LaravelMautic\Contracts\ContactReader;
use Trianity\LaravelMautic\Contracts\ContactReaderProvider;
use Trianity\LaravelMautic\MauticManager;

final readonly class ManagerContactReaderProvider implements ContactReaderProvider
{
    public function __construct(private MauticManager $manager) {}

    public function forConnection(string $connection): ContactReader
    {
        return $this->manager->connection($connection)->contactReads();
    }
}
