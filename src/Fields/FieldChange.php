<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Fields;

use LogicException;

final readonly class FieldChange
{
    private function __construct(public FieldChangeState $state, private mixed $storedValue = null) {}

    public static function unchanged(): self
    {
        return new self(FieldChangeState::Unchanged);
    }

    public static function clear(): self
    {
        return new self(FieldChangeState::Clear);
    }

    public static function set(mixed $value): self
    {
        if ($value === null) {
            return self::clear();
        }

        return new self(FieldChangeState::Set, $value);
    }

    public function value(): mixed
    {
        if ($this->state !== FieldChangeState::Set) {
            throw new LogicException('Only a set field change has a value.');
        }

        return $this->storedValue;
    }
}
