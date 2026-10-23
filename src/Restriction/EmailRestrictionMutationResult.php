<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Restriction;

use Trianity\LaravelMautic\Data\Contact;
use Trianity\LaravelMautic\Data\DoNotContactEntry;

final readonly class EmailRestrictionMutationResult
{
    /**
     * @param  list<DoNotContactEntry>  $emailRestrictions
     */
    public function __construct(
        public RestrictionMutationOutcome $outcome,
        public Contact $contact,
        public array $emailRestrictions,
        public ?bool $submittedCommentsObserved,
        public ?bool $recordFound,
    ) {}
}
