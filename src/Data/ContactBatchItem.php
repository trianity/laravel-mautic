<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Data;

use Trianity\LaravelMautic\Write\BatchItemOutcome;
use Trianity\LaravelMautic\Write\FailureKind;

final readonly class ContactBatchItem
{
    private function __construct(
        public int $requestIndex,
        public BatchItemOutcome $outcome,
        public ?Contact $contact,
        public ?FailureKind $failureKind,
        public ?int $httpStatus,
    ) {}

    public static function successful(int $requestIndex, Contact $contact, int $httpStatus): self
    {
        return new self($requestIndex, BatchItemOutcome::Successful, $contact, null, $httpStatus);
    }

    public static function failed(
        int $requestIndex,
        FailureKind $failureKind,
        ?int $httpStatus,
    ): self {
        return new self($requestIndex, BatchItemOutcome::Failed, null, $failureKind, $httpStatus);
    }

    public static function unknown(int $requestIndex, ?int $httpStatus = null): self
    {
        return new self(
            $requestIndex,
            BatchItemOutcome::Unknown,
            null,
            FailureKind::UnexpectedResponse,
            $httpStatus,
        );
    }
}
