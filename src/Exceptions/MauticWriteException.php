<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Exceptions;

use RuntimeException;
use Trianity\LaravelMautic\Write\FailureKind;
use Trianity\LaravelMautic\Write\MutationOperation;

class MauticWriteException extends RuntimeException
{
    public function __construct(
        public readonly FailureKind $kind,
        public readonly MutationOperation $operation,
        public readonly string $connection,
        public readonly ?int $httpStatus = null,
        public readonly ?int $batchIndex = null,
    ) {
        parent::__construct(sprintf(
            'Mautic mutation failed (%s, operation: %s, connection: %s%s%s).',
            $kind->value,
            $operation->value,
            $connection,
            $httpStatus === null ? '' : ', HTTP '.$httpStatus,
            $batchIndex === null ? '' : ', batch item '.$batchIndex,
        ));
    }
}
