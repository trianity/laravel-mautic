<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\OAuth;

use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Contracts\OAuthRefreshLock;
use Trianity\LaravelMautic\Contracts\OAuthTokenEndpoint;
use Trianity\LaravelMautic\Contracts\OAuthTokenRepository;
use Trianity\LaravelMautic\Data\OAuthToken;
use Trianity\LaravelMautic\Exceptions\OAuthReauthorizationRequired;

final readonly class OAuthTokenManager
{
    public function __construct(
        private OAuthTokenRepository $tokens,
        private OAuthTokenEndpoint $endpoint,
        private OAuthRefreshLock $lock,
        private Clock $clock,
        private int $expiryLeewaySeconds = 60,
        private int $lockWaitSeconds = 5,
        private int $lockLeaseSeconds = 30,
    ) {}

    public function validToken(string $connection): OAuthToken
    {
        $token = $this->tokens->get($connection);
        if ($token?->isUsableAt($this->clock->now(), $this->expiryLeewaySeconds)) {
            return $token;
        }

        return $this->lock->block(
            $connection,
            $this->lockWaitSeconds,
            $this->lockLeaseSeconds,
            function () use ($connection): OAuthToken {
                $current = $this->tokens->get($connection);
                if ($current?->isUsableAt($this->clock->now(), $this->expiryLeewaySeconds)) {
                    return $current;
                }
                if ($current === null || $current->refreshToken === '') {
                    throw new OAuthReauthorizationRequired($connection);
                }

                $rotated = $this->endpoint->refresh($connection, $current);
                $this->tokens->save($connection, $rotated);

                return $rotated;
            },
        );
    }
}
