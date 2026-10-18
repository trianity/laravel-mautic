<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Data;

final readonly class ContactPage
{
    /** @param list<Contact> $contacts */
    public function __construct(
        public array $contacts,
        public int $total,
        public int $offset,
        public int $limit,
    ) {}
}
