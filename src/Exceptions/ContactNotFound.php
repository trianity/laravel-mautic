<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Exceptions;

use Trianity\LaravelMautic\Read\ReadFailureKind;
use Trianity\LaravelMautic\Read\ReadOperation;

final class ContactNotFound extends MauticReadException
{
    public function __construct(string $connection, ReadOperation $operation = ReadOperation::FindContact)
    {
        parent::__construct(ReadFailureKind::NotFound, $operation, $connection, 404);
    }
}
