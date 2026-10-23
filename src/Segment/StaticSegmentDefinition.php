<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Segment;

use InvalidArgumentException;

final readonly class StaticSegmentDefinition
{
    public function __construct(public string $name, public string $alias, public bool $global = true)
    {
        if (trim($name) === '' || strlen($name) > 191 || trim($alias) === '' || strlen($alias) > 191) {
            throw new InvalidArgumentException('Static segment names and aliases must be non-empty bounded values.');
        }
    }

    /** @return array{name: string, alias: string, isPublished: true, isGlobal: bool, filters: array{}} */
    public function payload(): array
    {
        return [
            'name' => $this->name,
            'alias' => $this->alias,
            'isPublished' => true,
            'isGlobal' => $this->global,
            'filters' => [],
        ];
    }
}
