<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Tests\Support;

use RuntimeException;

final readonly class OAuthCustomFieldPreflight
{
    public function locate(mixed $response, string $expectedAlias): int
    {
        if (! is_array($response) || array_key_exists('errors', $response)
            || ! is_array($response['fields'] ?? null)) {
            throw new RuntimeException('The configured OAuth test custom field is missing or inaccessible.');
        }

        $matches = [];
        foreach ($response['fields'] as $field) {
            if (is_array($field) && ($field['alias'] ?? null) === $expectedAlias) {
                $matches[] = $field;
            }
        }

        if (count($matches) !== 1 || ! is_int($matches[0]['id'] ?? null)) {
            throw new RuntimeException('The configured OAuth test custom-field alias must resolve to exactly one field.');
        }

        return $matches[0]['id'];
    }

    public function validate(mixed $response, int $expectedId, string $expectedAlias): OAuthCustomFieldDefinition
    {
        if (! is_array($response) || array_key_exists('errors', $response)) {
            throw new RuntimeException('The configured OAuth test custom field is missing or inaccessible.');
        }

        $field = $response['field'] ?? null;
        if (! is_array($field)
            || ($field['id'] ?? null) !== $expectedId
            || ($field['alias'] ?? null) !== $expectedAlias
            || ! is_string($field['label'] ?? null)
            || trim($field['label']) === ''
            || ($field['type'] ?? null) !== 'text'
            || ($field['object'] ?? null) !== 'lead'
            || ($field['isPublished'] ?? null) !== true
            || ($field['isRequired'] ?? null) !== false
            || ($field['isUniqueIdentifier'] ?? null) !== false) {
            throw new RuntimeException('The configured OAuth test custom field is not the expected dedicated published contact text field.');
        }

        return new OAuthCustomFieldDefinition($expectedId, $expectedAlias);
    }
}
