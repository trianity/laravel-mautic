<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Data;

final readonly class ContactBatch
{
    /** @var list<Contact> */
    public array $contacts;

    /** @param list<ContactBatchItem> $items */
    public function __construct(public array $items)
    {
        $contacts = [];
        foreach ($items as $item) {
            if ($item->contact !== null) {
                $contacts[] = $item->contact;
            }
        }

        $this->contacts = $contacts;
    }
}
