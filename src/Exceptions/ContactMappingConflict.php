<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Exceptions;

use RuntimeException;

final class ContactMappingConflict extends RuntimeException
{
    public function __construct(public readonly string $connection)
    {
        parent::__construct('The local or remote contact identity is already claimed by another mapping.');
    }
}
