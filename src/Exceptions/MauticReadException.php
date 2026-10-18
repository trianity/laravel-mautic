<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Exceptions;

use RuntimeException;
use Trianity\LaravelMautic\Read\ReadFailureKind;
use Trianity\LaravelMautic\Read\ReadOperation;

class MauticReadException extends RuntimeException
{
    public function __construct(
        public readonly ReadFailureKind $kind,
        public readonly ReadOperation $operation,
        public readonly string $connection,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct(sprintf(
            'Mautic read failed (%s, operation: %s, connection: %s%s).',
            $kind->value,
            $operation->value,
            $connection,
            $httpStatus === null ? '' : ', HTTP '.$httpStatus,
        ));
    }
}
