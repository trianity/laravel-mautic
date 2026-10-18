<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Exceptions;

use Trianity\LaravelMautic\Write\FailureKind;
use Trianity\LaravelMautic\Write\MutationOperation;

final class AmbiguousMutationOutcome extends MauticWriteException
{
    public function __construct(MutationOperation $operation, string $connection, ?int $batchIndex = null)
    {
        parent::__construct(FailureKind::AmbiguousOutcome, $operation, $connection, null, $batchIndex);
    }
}
