<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Write;

use Trianity\LaravelMautic\Read\OperationSafety;

enum MutationOperation: string
{
    case CreateContact = 'create_contact';
    case CreateContactsBatch = 'create_contacts_batch';
    case UpdateContact = 'update_contact';
    case UpdateContactsBatch = 'update_contacts_batch';
    case AddContactToSegment = 'add_contact_to_segment';
    case RemoveContactFromSegment = 'remove_contact_from_segment';

    public function safety(): OperationSafety
    {
        return new OperationSafety(
            sideEffectFree: false,
            mayRetry: false,
            ambiguousOnTransportFailure: true,
        );
    }
}
