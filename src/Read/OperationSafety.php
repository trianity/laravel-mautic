<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Read;

final readonly class OperationSafety
{
    public function __construct(
        public bool $sideEffectFree,
        public bool $mayRetry,
        public bool $ambiguousOnTransportFailure = false,
    ) {}
}
