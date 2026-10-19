<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Contracts;

use Trianity\LaravelMautic\Identity\ContactMapping;
use Trianity\LaravelMautic\Identity\LocalContactReference;
use Trianity\LaravelMautic\Identity\RemoteContactState;

interface ContactMappingRepository
{
    public function find(string $connection, LocalContactReference $localReference): ?ContactMapping;

    public function findByRemoteId(string $connection, int $remoteId): ?ContactMapping;

    public function claim(string $connection, LocalContactReference $localReference, int $remoteId): ContactMapping;

    public function markRemoteState(
        ContactMapping $expected,
        RemoteContactState $state,
        ?int $mergedIntoRemoteId = null,
    ): ContactMapping;

    public function relink(ContactMapping $expected, int $newRemoteId): ContactMapping;
}
