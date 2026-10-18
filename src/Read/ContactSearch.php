<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Read;

use Trianity\LaravelMautic\Exceptions\InvalidReadRequest;

final readonly class ContactSearch
{
    public function __construct(
        public string $query,
        public int $offset,
        public int $limit,
    ) {
        if ($offset < 0) {
            throw new InvalidReadRequest('The search offset must be zero or greater.');
        }

        if ($limit < 1 || $limit > 100) {
            throw new InvalidReadRequest('The search limit must be between 1 and 100.');
        }
    }
}
