<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Tests\Support;

final readonly class OAuthStaticSegmentDefinition
{
    public function __construct(
        public int $remoteId,
        public string $name,
    ) {}
}
