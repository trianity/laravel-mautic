<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Exceptions;

use Trianity\LaravelMautic\Write\FailureKind;
use Trianity\LaravelMautic\Write\MutationOperation;

final class ContactUpdateTargetNotFound extends MauticWriteException
{
    public function __construct(string $connection, MutationOperation $operation = MutationOperation::UpdateContact, ?int $batchIndex = null)
    {
        parent::__construct(FailureKind::TargetNotFound, $operation, $connection, 404, $batchIndex);
    }
}
