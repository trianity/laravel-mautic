<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Reconciliation;

use Trianity\LaravelMautic\Data\Contact;

final readonly class ReconciliationPage
{
    /** @param list<Contact> $contacts */
    public function __construct(
        public array $contacts,
        public int $remainingCount,
    ) {}
}
