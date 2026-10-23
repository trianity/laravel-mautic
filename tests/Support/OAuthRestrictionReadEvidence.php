<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Tests\Support;

use RuntimeException;

final readonly class OAuthRestrictionReadEvidence
{
    public function write(
        string $status,
        int $requestAttempts,
        bool $tokenRefreshed,
        ?string $storageDirectory = null,
    ): string {
        if (! in_array($status, ['passed', 'failed'], true)) {
            throw new RuntimeException('The restriction-read evidence status is invalid.');
        }

        $directory = $storageDirectory ?? dirname(__DIR__).'/.oauth-acceptance';
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            throw new RuntimeException('The restriction-read evidence directory could not be created.');
        }
        if (! chmod($directory, 0700)) {
            throw new RuntimeException('The restriction-read evidence directory could not be restricted.');
        }

        $path = $directory.'/restriction-read-corrected.json';
        $payload = json_encode([
            'status' => $status,
            'request_attempts' => $requestAttempts,
            'token_refreshed' => $tokenRefreshed,
            'mutation_requests' => 0,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
        if (file_put_contents($path, $payload, LOCK_EX) !== strlen($payload)
            || ! chmod($path, 0600)) {
            throw new RuntimeException('The restriction-read evidence could not be stored securely.');
        }

        return $path;
    }
}
