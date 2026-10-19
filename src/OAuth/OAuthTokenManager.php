<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\OAuth;

use Throwable;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Contracts\OAuthRefreshLock;
use Trianity\LaravelMautic\Contracts\OAuthTokenEndpoint;
use Trianity\LaravelMautic\Contracts\OAuthTokenRepository;
use Trianity\LaravelMautic\Data\OAuthToken;
use Trianity\LaravelMautic\Exceptions\InvalidConfiguration;
use Trianity\LaravelMautic\Exceptions\OAuthReauthorizationRequired;
use Trianity\LaravelMautic\Exceptions\OAuthTokenPersistenceUncertain;

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
    ) {
        if ($expiryLeewaySeconds < 0 || $lockWaitSeconds <= 0 || $lockLeaseSeconds <= 0) {
            throw new InvalidConfiguration('OAuth timing settings are invalid.');
        }
    }

    public function assertSupportsRequestTimeout(float $requestTimeout): void
    {
        if ($this->lockLeaseSeconds <= $requestTimeout) {
            throw new InvalidConfiguration('OAuth lock_lease_seconds must exceed the request timeout.');
        }
    }

    public function validToken(string $connection): OAuthToken
    {
        $token = $this->tokens->record($connection)?->token;
        if ($token?->isUsableAt($this->clock->now(), $this->expiryLeewaySeconds)) {
            return $token;
        }

        return $this->lock->block(
            $connection,
            $this->lockWaitSeconds,
            $this->lockLeaseSeconds,
            function () use ($connection): OAuthToken {
                $current = $this->tokens->record($connection);
                if ($current?->token->isUsableAt($this->clock->now(), $this->expiryLeewaySeconds)) {
                    return $current->token;
                }
                if ($current === null || $current->token->refreshToken === '') {
                    throw new OAuthReauthorizationRequired($connection);
                }

                $rotated = $this->endpoint->refresh($connection, $current->token);
                try {
                    $saved = $this->tokens->persist($connection, $rotated, $current->generation);
                } catch (Throwable) {
                    throw new OAuthTokenPersistenceUncertain($connection);
                }

                if (! $saved) {
                    $newer = $this->tokens->record($connection)?->token;
                    if ($newer?->isUsableAt($this->clock->now(), $this->expiryLeewaySeconds)) {
                        return $newer;
                    }

                    throw new OAuthTokenPersistenceUncertain($connection);
                }

                return $rotated;
            },
        );
    }
}
