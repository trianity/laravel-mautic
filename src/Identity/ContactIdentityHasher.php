<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Identity;

final class ContactIdentityHasher
{
    public static function connection(string $connection): string
    {
        return self::tuple('connection-v1', $connection);
    }

    public static function local(string $connection, LocalContactReference $reference): string
    {
        return self::tuple('local-v1', $connection, $reference->scope, $reference->key);
    }

    public static function remote(string $connection, int $remoteId): string
    {
        return self::tuple('remote-v1', $connection, (string) $remoteId);
    }

    private static function tuple(string ...$components): string
    {
        $encoded = '';
        foreach ($components as $component) {
            $encoded .= pack('N', strlen($component)).$component;
        }

        return hash('sha256', $encoded);
    }
}
