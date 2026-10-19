<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Fields;

use Trianity\LaravelMautic\Data\OptionalValue;
use Trianity\LaravelMautic\Exceptions\InvalidFieldMapping;

final readonly class HostContactFields
{
    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, true>  $allowed
     */
    private function __construct(private array $values, private array $allowed) {}

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $allowed
     */
    public static function fromArray(array $values, array $allowed): self
    {
        $allowedLookup = [];
        foreach ($allowed as $alias) {
            if (trim($alias) === '') {
                throw new InvalidFieldMapping('Allowed host field aliases cannot be blank.');
            }
            $allowedLookup[$alias] = true;
        }
        foreach ($values as $alias => $value) {
            if (! isset($allowedLookup[$alias])) {
                throw new InvalidFieldMapping('Every host contact field must be explicitly allowlisted.');
            }
            self::validateValue($value);
        }

        return new self($values, $allowedLookup);
    }

    /** @return OptionalValue<mixed> */
    public function field(string $alias): OptionalValue
    {
        return array_key_exists($alias, $this->values)
            ? OptionalValue::present($this->values[$alias])
            : OptionalValue::missing();
    }

    public function allows(string $alias): bool
    {
        return isset($this->allowed[$alias]);
    }

    private static function validateValue(mixed $value): void
    {
        if ($value === null || is_scalar($value)) {
            return;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($item !== null && ! is_scalar($item)) {
                    throw new InvalidFieldMapping('Host contact field lists may contain only scalar or null values.');
                }
            }

            return;
        }

        throw new InvalidFieldMapping('Host contact fields must contain scalar, null or list values.');
    }
}
