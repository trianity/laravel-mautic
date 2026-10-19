<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Support;

use Mautic\Api\Api;

final class SdkResponseMetadata
{
    /** @param array<mixed> $response */
    public static function retryAfterForResponse(array $response, Api $api): ?int
    {
        $errors = $response['errors'] ?? null;
        if (! is_array($errors) || $errors === []) {
            return null;
        }
        $first = reset($errors);
        $status = is_array($first) ? self::integer($first['code'] ?? null) : null;
        if ($status === null || $status < 100 || $status > 599) {
            return null;
        }

        foreach ($api->getResponseHeaders() as $name => $value) {
            if (! is_string($name) || strtolower($name) !== 'retry-after') {
                continue;
            }
            if (is_array($value)) {
                $value = reset($value);
            }

            return is_string($value) && ctype_digit($value) ? (int) $value : null;
        }

        return null;
    }

    private static function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}
