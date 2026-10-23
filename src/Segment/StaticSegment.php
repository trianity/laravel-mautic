<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Segment;

final readonly class StaticSegment
{
    public function __construct(
        public int $remoteId,
        public string $name,
        public string $alias,
        public bool $published,
        public bool $global,
        /** @var list<array<string, mixed>> */
        public array $filters,
    ) {}

    public function suitable(): bool
    {
        return $this->published && $this->filters === [];
    }
}
