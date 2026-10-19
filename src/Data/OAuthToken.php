<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Data;

use SensitiveParameter;

final readonly class OAuthToken
{
    public function __construct(
        #[SensitiveParameter] public string $accessToken,
        #[SensitiveParameter] public string $refreshToken,
        public int $expiresAt,
        public string $tokenType = 'bearer',
    ) {}

    public function isUsableAt(int $timestamp, int $leewaySeconds): bool
    {
        return $this->accessToken !== '' && $this->expiresAt > $timestamp + $leewaySeconds;
    }
}
