<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Tests\Support;

use RuntimeException;

final readonly class OAuthStaticSegmentPreflight
{
    public const EXPECTED_NAME = 'lm-live-mautic721-static';

    public function validate(mixed $response, int $expectedId): OAuthStaticSegmentDefinition
    {
        if (! is_array($response) || array_key_exists('errors', $response)) {
            throw new RuntimeException('The configured OAuth test segment is missing or inaccessible.');
        }

        $segment = $response['list'] ?? null;
        if (! is_array($segment)
            || ($segment['id'] ?? null) !== $expectedId
            || ($segment['name'] ?? null) !== self::EXPECTED_NAME
            || ! array_key_exists('filters', $segment)
            || ! is_array($segment['filters'])) {
            throw new RuntimeException('The configured OAuth test segment definition is not the expected dedicated fixture.');
        }
        if ($segment['filters'] !== []) {
            throw new RuntimeException('The configured OAuth test segment must have no dynamic filters.');
        }

        return new OAuthStaticSegmentDefinition($expectedId, self::EXPECTED_NAME);
    }
}
