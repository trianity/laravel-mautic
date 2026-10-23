<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Exceptions;

use RuntimeException;

final class SegmentNotFound extends RuntimeException
{
    public function __construct(public readonly string $connection)
    {
        parent::__construct('The requested Mautic segment was not found.');
    }
}
