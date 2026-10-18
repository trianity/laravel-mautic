<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Data;

use LogicException;

/** @template-covariant T */
final readonly class OptionalValue
{
    /** @param T|null $storedValue */
    private function __construct(
        public ValuePresence $presence,
        private mixed $storedValue = null,
    ) {}

    /** @return self<never> */
    public static function missing(): self
    {
        return new self(ValuePresence::Missing);
    }

    /** @return self<never> */
    public static function null(): self
    {
        return new self(ValuePresence::Null);
    }

    /**
     * @template TValue
     *
     * @param  TValue  $value
     * @return self<TValue>
     */
    public static function present(mixed $value): self
    {
        if ($value === null) {
            return self::null();
        }

        return new self(ValuePresence::Value, $value);
    }

    public function isPresent(): bool
    {
        return $this->presence !== ValuePresence::Missing;
    }

    /** @return T|null */
    public function value(): mixed
    {
        if ($this->presence === ValuePresence::Missing) {
            throw new LogicException('A missing value has no value.');
        }

        return $this->storedValue;
    }
}
