<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Exceptions;

use RuntimeException;

final class ContactMappingChanged extends RuntimeException
{
    public function __construct(public readonly string $connection)
    {
        parent::__construct('The contact mapping changed before the requested transition was stored.');
    }
}
