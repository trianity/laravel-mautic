<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Identity;

use Trianity\LaravelMautic\Data\Contact;

final readonly class ContactDiscoveryResult
{
    public ContactDiscoveryOutcome $outcome;

    /** @param list<Contact> $contacts */
    public function __construct(public string $normalizedEmail, public array $contacts)
    {
        $this->outcome = match (count($contacts)) {
            0 => ContactDiscoveryOutcome::None,
            1 => ContactDiscoveryOutcome::Unique,
            default => ContactDiscoveryOutcome::Ambiguous,
        };
    }

    public function uniqueContact(): ?Contact
    {
        return $this->outcome === ContactDiscoveryOutcome::Unique ? $this->contacts[0] : null;
    }
}
