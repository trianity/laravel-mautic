<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Identity;

final readonly class ContactMapping
{
    public function __construct(
        public string $connection,
        public LocalContactReference $localReference,
        public int $remoteId,
        public RemoteContactState $remoteState,
        public ?int $mergedIntoRemoteId,
        public int $generation,
    ) {}
}
