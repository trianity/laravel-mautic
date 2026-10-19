<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Reconciliation;

use Trianity\LaravelMautic\Identity\ContactMapping;

final readonly class MappingInventoryPage
{
    /** @param list<ContactMapping> $mappings */
    public function __construct(
        public array $mappings,
        public int $nextRemoteId,
        public bool $complete,
    ) {}
}
