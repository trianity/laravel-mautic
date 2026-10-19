<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Tests\Support;

use Closure;
use RuntimeException;
use SensitiveParameter;
use Trianity\LaravelMautic\Data\OAuthToken;
use Trianity\LaravelMautic\Exceptions\OAuthTokenPersistenceUncertain;

final class RotatedTokenOutput
{
    /** @param resource $handle */
    private function __construct(private mixed $handle) {}

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    public static function prepare(
        string $path,
        ?Closure $open = null,
        ?Closure $restrict = null,
        ?Closure $permissions = null,
    ): self {
        if (file_exists($path)) {
            throw new RuntimeException('The rotated-token output could not be exclusively prepared.');
        }
        $open ??= static fn (string $path): mixed => @fopen($path, 'x+b');
        $restrict ??= static fn (string $path): bool => @chmod($path, 0600);
        $permissions ??= static fn (string $path): int|false => @fileperms($path);
        $handle = $open($path);
        if (! is_resource($handle)) {
            throw new RuntimeException('The rotated-token output could not be exclusively prepared.');
        }
        if (! $restrict($path)) {
            fclose($handle);
            @unlink($path);

            throw new RuntimeException('The rotated-token output permissions could not be restricted.');
        }
        clearstatcache(true, $path);
        $mode = $permissions($path);
        if (! is_int($mode) || ($mode & 0777) !== 0600) {
            fclose($handle);
            @unlink($path);

            throw new RuntimeException('The rotated-token output permissions could not be verified.');
        }

        return new self($handle);
    }

    public function persist(
        #[SensitiveParameter] OAuthToken $token,
        string $connection,
        ?Closure $write = null,
        ?Closure $flush = null,
    ): void {
        $payload = json_encode([
            'access_token' => $token->accessToken,
            'refresh_token' => $token->refreshToken,
            'expires_at' => $token->expiresAt,
            'token_type' => $token->tokenType,
        ], JSON_THROW_ON_ERROR)."\n";
        $write ??= static fn (mixed $handle, string $payload): int|false => fwrite($handle, $payload);
        $flush ??= static fn (mixed $handle): bool => fflush($handle) && fsync($handle);
        $written = $write($this->handle, $payload);
        if (! is_int($written) || $written !== strlen($payload) || ! $flush($this->handle)) {
            throw new OAuthTokenPersistenceUncertain($connection);
        }
        fclose($this->handle);
        $this->handle = null;
    }
}
