<?php

declare(strict_types=1);

use Closure;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Contracts\OAuthRefreshLock;
use Trianity\LaravelMautic\Contracts\OAuthTokenEndpoint;
use Trianity\LaravelMautic\Contracts\OAuthTokenRepository;
use Trianity\LaravelMautic\Data\OAuthToken;
use Trianity\LaravelMautic\Data\OAuthTokenRecord;
use Trianity\LaravelMautic\OAuth\OAuthTokenManager;
use Trianity\LaravelMautic\Tests\Support\ControlledOAuthRefresh;

it('measures the controlled two-generation refresh after valid or initially expired token use', function (bool $initiallyExpired) {
    $clock = new class implements Clock
    {
        public function now(): int
        {
            return 2_000_000_000;
        }
    };
    $repository = new class($initiallyExpired, $clock) implements OAuthTokenRepository
    {
        private OAuthTokenRecord $record;

        public function __construct(bool $expired, Clock $clock)
        {
            $this->record = new OAuthTokenRecord(new OAuthToken(
                'initial-access',
                'refresh',
                $clock->now() + ($expired ? -1 : 3600),
            ), 1);
        }

        public function get(string $connection): ?OAuthToken
        {
            return $this->record->token;
        }

        public function record(string $connection): ?OAuthTokenRecord
        {
            return $this->record;
        }

        public function persist(string $connection, OAuthToken $token, ?int $expectedGeneration): bool
        {
            if ($expectedGeneration !== $this->record->generation) {
                return false;
            }

            $this->record = new OAuthTokenRecord($token, $this->record->generation + 1);

            return true;
        }
    };
    $endpoint = new class($clock) implements OAuthTokenEndpoint
    {
        public function __construct(private Clock $clock) {}

        public function exchange(string $connection, string $code): OAuthToken
        {
            throw new LogicException('Not used.');
        }

        public function refresh(string $connection, OAuthToken $token): OAuthToken
        {
            return new OAuthToken('rotated-access', 'rotated-refresh', $this->clock->now() + 3600);
        }
    };
    $lock = new class implements OAuthRefreshLock
    {
        public function block(string $connection, int $waitSeconds, int $leaseSeconds, Closure $callback): mixed
        {
            return $callback();
        }
    };
    $manager = new OAuthTokenManager($repository, $endpoint, $lock, $clock);

    $manager->validToken('oauth-acceptance');
    $evidence = (new ControlledOAuthRefresh($repository, $manager, $clock))->run('oauth-acceptance');

    expect($evidence->after->generation)->toBe($evidence->before->generation + 2)
        ->and($evidence->after->token->accessToken)->toBe('rotated-access');
})->with([
    'valid initial token' => false,
    'initially expired token' => true,
]);
