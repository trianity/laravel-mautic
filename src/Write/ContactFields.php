<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Write;

use Trianity\LaravelMautic\Exceptions\InvalidWriteRequest;

final readonly class ContactFields
{
    private const PROTECTED_FIELDS = [
        'id',
        'donotcontact',
        'dnc',
        'tags',
        'dateadded',
        'datemodified',
        'lastactive',
    ];

    /** @param array<string, mixed> $values */
    private function __construct(private array $values) {}

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $allowed
     */
    public static function fromArray(array $values, array $allowed): self
    {
        if ($values === []) {
            throw new InvalidWriteRequest('At least one contact field is required.');
        }

        $allowedLookup = [];
        foreach ($allowed as $alias) {
            if ($alias === '') {
                throw new InvalidWriteRequest('Allowed contact field aliases cannot be blank.');
            }

            $allowedLookup[$alias] = true;
        }

        foreach ($values as $alias => $value) {
            if (! isset($allowedLookup[$alias])) {
                throw new InvalidWriteRequest('Every contact field must be explicitly allowlisted.');
            }

            if (in_array(strtolower($alias), self::PROTECTED_FIELDS, true)) {
                throw new InvalidWriteRequest('The requested field is outside generic contact writes.');
            }

            self::validateValue($value);
        }

        return new self($values);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return $this->values;
    }

    public function value(string $alias): mixed
    {
        return $this->values[$alias] ?? null;
    }

    public function has(string $alias): bool
    {
        return array_key_exists($alias, $this->values);
    }

    private static function validateValue(mixed $value): void
    {
        if ($value === null || is_scalar($value)) {
            return;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if ($item !== null && ! is_scalar($item)) {
                    throw new InvalidWriteRequest('Contact field arrays may contain only scalar or null values.');
                }
            }

            return;
        }

        throw new InvalidWriteRequest('Contact fields must contain JSON-compatible scalar values or lists.');
    }
}
