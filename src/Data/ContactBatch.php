<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Data;

final readonly class ContactBatch
{
    /** @param list<Contact> $contacts */
    public function __construct(public array $contacts) {}
}
