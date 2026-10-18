<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Write;

final readonly class ContactUpdate
{
    public function __construct(
        public int $remoteId,
        public ContactFields $fields,
    ) {}
}
