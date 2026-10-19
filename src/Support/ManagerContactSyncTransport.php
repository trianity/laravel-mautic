<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Support;

use Trianity\LaravelMautic\Contracts\ContactSyncTransport;
use Trianity\LaravelMautic\MauticManager;
use Trianity\LaravelMautic\Write\ContactFields;

final readonly class ManagerContactSyncTransport implements ContactSyncTransport
{
    public function __construct(private MauticManager $manager) {}

    public function create(string $connection, ContactFields $fields): int
    {
        return $this->manager->connection($connection)->contactWrites()->create($fields)->remoteId;
    }

    public function update(string $connection, int $remoteId, ContactFields $fields): void
    {
        $this->manager->connection($connection)->contactWrites()->update($remoteId, $fields);
    }
}
