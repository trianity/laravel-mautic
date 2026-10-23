<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Exceptions;

use Trianity\LaravelMautic\Write\FailureKind;
use Trianity\LaravelMautic\Write\MutationOperation;

final class RestrictionStateNotVerified extends MauticWriteException
{
    public readonly bool $mutationMayHaveCompleted;

    public function __construct(MutationOperation $operation, string $connection)
    {
        $this->mutationMayHaveCompleted = true;

        parent::__construct(FailureKind::UnexpectedResponse, $operation, $connection);
    }
}
