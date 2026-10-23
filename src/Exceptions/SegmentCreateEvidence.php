<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Exceptions;

use RuntimeException;

final class SegmentCreateEvidence extends RuntimeException
{
    public function __construct(
        public readonly int $remoteId,
        string $message = 'The created segment response exposed an ID but could not be verified.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
