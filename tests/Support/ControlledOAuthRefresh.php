<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Tests\Support;

use RuntimeException;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Contracts\OAuthTokenRepository;
use Trianity\LaravelMautic\Data\OAuthToken;
use Trianity\LaravelMautic\OAuth\OAuthTokenManager;

final readonly class ControlledOAuthRefresh
{
    public function __construct(
        private OAuthTokenRepository $tokens,
        private OAuthTokenManager $tokenManager,
        private Clock $clock,
    ) {}

    public function run(string $connection): OAuthRefreshEvidence
    {
        $before = $this->tokens->record($connection);
        if ($before === null) {
            throw new RuntimeException('Complete browser authorization before running OAuth acceptance probes.');
        }

        $expired = new OAuthToken(
            $before->token->accessToken,
            $before->token->refreshToken,
            $this->clock->now() - 1,
            $before->token->tokenType,
        );
        if (! $this->tokens->persist($connection, $expired, $before->generation)) {
            throw new RuntimeException('The dedicated acceptance token could not be marked expired safely.');
        }

        $this->tokenManager->validToken($connection);
        $after = $this->tokens->record($connection);
        if ($after === null) {
            throw new RuntimeException('The refreshed dedicated acceptance token is unavailable.');
        }

        return new OAuthRefreshEvidence($before, $after);
    }
}
