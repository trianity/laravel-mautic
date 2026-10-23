<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Tests\Support;

use RuntimeException;

final readonly class OAuthRestrictionWriteEvidence
{
    /** @param array<string, array<string, bool|int|string>> $scenarios */
    public function write(array $scenarios, ?string $storageDirectory = null, string $status = 'passed'): string
    {
        if (! in_array($status, ['passed', 'failed'], true)) {
            throw new RuntimeException('The restriction-write evidence status is invalid.');
        }

        $directory = $storageDirectory ?? dirname(__DIR__).'/.oauth-acceptance';
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            throw new RuntimeException('The restriction-write evidence directory could not be created.');
        }
        if (! chmod($directory, 0700)) {
            throw new RuntimeException('The restriction-write evidence directory could not be restricted.');
        }

        $path = $directory.'/restriction-write.json';
        $payload = json_encode([
            'status' => $status,
            'scenarios' => $scenarios,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
        if (file_put_contents($path, $payload, LOCK_EX) !== strlen($payload)
            || ! chmod($path, 0600)) {
            throw new RuntimeException('The restriction-write evidence could not be stored securely.');
        }

        return $path;
    }
}
