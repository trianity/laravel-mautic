<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Data;

final readonly class ContactTag
{
    public function __construct(
        public ?int $remoteId,
        public string $name,
    ) {}
}
