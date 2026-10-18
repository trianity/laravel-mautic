<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Exceptions;

use Trianity\LaravelMautic\Write\FailureKind;
use Trianity\LaravelMautic\Write\MutationOperation;

final class ContactAlreadyExists extends MauticWriteException
{
    public function __construct(string $connection, MutationOperation $operation = MutationOperation::CreateContact)
    {
        parent::__construct(FailureKind::DuplicateContact, $operation, $connection);
    }
}
