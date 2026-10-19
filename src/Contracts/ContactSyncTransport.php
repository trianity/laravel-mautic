<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Contracts;

use Trianity\LaravelMautic\Write\ContactFields;

interface ContactSyncTransport
{
    public function create(string $connection, ContactFields $fields): int;

    public function update(string $connection, int $remoteId, ContactFields $fields): void;
}
