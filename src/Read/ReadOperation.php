<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Read;

enum ReadOperation: string
{
    case FindContact = 'find_contact';
    case SearchContacts = 'search_contacts';
    case ListContactSegments = 'list_contact_segments';

    public function safety(): OperationSafety
    {
        return new OperationSafety(sideEffectFree: true, mayRetry: true);
    }
}
