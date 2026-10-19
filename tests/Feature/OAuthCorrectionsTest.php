<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Psr\Http\Client\ClientInterface;
use Trianity\LaravelMautic\Configuration\ConnectionConfig;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Contracts\HttpClientFactory;
use Trianity\LaravelMautic\Contracts\OAuthClientCredentialsProvider;
use Trianity\LaravelMautic\Contracts\OAuthRefreshLock;
use Trianity\LaravelMautic\Contracts\OAuthTokenEndpoint;
use Trianity\LaravelMautic\Contracts\OAuthTokenRepository;
use Trianity\LaravelMautic\Data\OAuthClientCredentials;
use Trianity\LaravelMautic\Data\OAuthToken;
use Trianity\LaravelMautic\Data\OAuthTokenRecord;
use Trianity\LaravelMautic\Exceptions\AmbiguousMutationOutcome;
use Trianity\LaravelMautic\Exceptions\InvalidConfiguration;
use Trianity\LaravelMautic\Exceptions\InvalidOAuthState;
use Trianity\LaravelMautic\Exceptions\OAuthLockTimeout;
use Trianity\LaravelMautic\Exceptions\OAuthPreRequestFailed;
use Trianity\LaravelMautic\Exceptions\OAuthReauthorizationRequired;
use Trianity\LaravelMautic\Exceptions\OAuthTokenPersistenceUncertain;
use Trianity\LaravelMautic\Exceptions\OAuthTokenRequestFailed;
use Trianity\LaravelMautic\MauticConnection;
use Trianity\LaravelMautic\OAuth\DatabaseOAuthStateRepository;
use Trianity\LaravelMautic\OAuth\DatabaseOAuthTokenRepository;
use Trianity\LaravelMautic\OAuth\DatabaseRefreshLock;
use Trianity\LaravelMautic\OAuth\HttpOAuthTokenEndpoint;
use Trianity\LaravelMautic\OAuth\ManagedOAuthAuth;
use Trianity\LaravelMautic\OAuth\OAuth2Service;
use Trianity\LaravelMautic\OAuth\OAuthTokenFailureKind;
use Trianity\LaravelMautic\OAuth\OAuthTokenManager;
use Trianity\LaravelMautic\OAuth\SystemClock;
use Trianity\LaravelMautic\Write\ContactFields;

beforeEach(function () {
    config()->set('database.default', 'oauth-corrections');
    config()->set('database.connections.oauth-corrections', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    DB::purge('oauth-corrections');

    Schema::connection('oauth-corrections')->create('mautic_oauth_tokens', function (Blueprint $table): void {
        $table->string('connection')->primary();
        $table->text('token_payload');
        $table->unsignedBigInteger('expires_at');
        $table->unsignedBigInteger('generation');
        $table->timestamps();
    });
    Schema::connection('oauth-corrections')->create('mautic_oauth_states', function (Blueprint $table): void {
        $table->string('state_hash', 64)->primary();
        $table->string('connection');
        $table->string('context_hash', 64);
        $table->unsignedBigInteger('expires_at');
        $table->timestamp('created_at');
    });
    Schema::connection('oauth-corrections')->create('mautic_oauth_locks', function (Blueprint $table): void {
        $table->string('name')->primary();
        $table->string('owner', 64);
        $table->unsignedBigInteger('expires_at');
    });

    config()->set('mautic.connections.main', [
        'url' => 'https://mautic.example.test/marketing/api/',
        'auth' => 'oauth2',
        'oauth' => [
            'client_id' => 'client-id',
            'client_secret_env' => 'SECRET_NAME_ONLY',
            'redirect_uri' => 'https://app.example.test/callback',
        ],
    ]);
});

function correctionCredentials(): OAuthClientCredentialsProvider
{
    return new class implements OAuthClientCredentialsProvider
    {
        public function forConnection(string $connection): OAuthClientCredentials
        {
            return new OAuthClientCredentials('client-id', 'client-secret');
        }
    };
}

function correctionClient(ClientInterface $client): HttpClientFactory
{
    return new class($client) implements HttpClientFactory
    {
        public function __construct(private readonly ClientInterface $client) {}

        public function make(ConnectionConfig $config): ClientInterface
        {
            return $this->client;
        }
    };
}

it('binds state to trusted initiating context without consuming it on mismatch', function () {
    $states = new DatabaseOAuthStateRepository(DB::getFacadeRoot(), new SystemClock, 'oauth-corrections');
    $tokens = new DatabaseOAuthTokenRepository(DB::getFacadeRoot(), Crypt::getFacadeRoot(), 'oauth-corrections');
    $endpoint = Mockery::mock(OAuthTokenEndpoint::class);
    $endpoint->shouldReceive('exchange')->once()->with('main', 'valid-code')
        ->andReturn(new OAuthToken('access', 'refresh', time() + 3600));
    $service = new OAuth2Service(
        config(), $states, $tokens, $endpoint,
        new DatabaseRefreshLock(DB::getFacadeRoot(), new SystemClock, 'oauth-corrections', 1),
        300,
    );

    $url = $service->authorizationUrl('main', 'trusted-server-side-context');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $state = (string) ($query['state'] ?? '');

    expect(fn () => $service->complete('main', $state, 'valid-code', null))
        ->toThrow(InvalidOAuthState::class)
        ->and(fn () => $service->complete('main', $state, 'valid-code', 'wrong-context'))
        ->toThrow(InvalidOAuthState::class);

    $token = $service->complete('main', $state, 'valid-code', 'trusted-server-side-context');

    expect($token->accessToken)->toBe('access')
        ->and(fn () => $service->complete('main', $state, 'replay', 'trusted-server-side-context'))
        ->toThrow(InvalidOAuthState::class);
});

it('validates the lock lease before consuming callback state', function () {
    $states = new DatabaseOAuthStateRepository(DB::getFacadeRoot(), new SystemClock, 'oauth-corrections');
    $tokens = new DatabaseOAuthTokenRepository(DB::getFacadeRoot(), Crypt::getFacadeRoot(), 'oauth-corrections');
    $endpoint = Mockery::mock(OAuthTokenEndpoint::class);
    $endpoint->shouldReceive('exchange')->once()->andReturn(new OAuthToken('access', 'refresh', time() + 3600));
    $service = new OAuth2Service(
        config(), $states, $tokens, $endpoint,
        new DatabaseRefreshLock(DB::getFacadeRoot(), new SystemClock, 'oauth-corrections', 1),
        300, 2, 5,
    );
    $url = $service->authorizationUrl('main', 'trusted-context');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $state = (string) $query['state'];

    expect(fn () => $service->complete('main', $state, 'code', 'trusted-context'))
        ->toThrow(InvalidConfiguration::class);

    config()->set('mautic.connections.main.timeout', 1);
    config()->set('mautic.connections.main.connect_timeout', 1);

    expect($service->complete('main', $state, 'code', 'trusted-context')->accessToken)->toBe('access');
});

it('builds authorization and token endpoints from the canonical instance root', function (string $configuredUrl) {
    config()->set('mautic.connections.main.url', $configuredUrl);
    $states = new DatabaseOAuthStateRepository(DB::getFacadeRoot(), new SystemClock, 'oauth-corrections');
    $tokens = new DatabaseOAuthTokenRepository(DB::getFacadeRoot(), Crypt::getFacadeRoot(), 'oauth-corrections');
    $tokenApi = Mockery::mock(OAuthTokenEndpoint::class);
    $service = new OAuth2Service(
        config(), $states, $tokens, $tokenApi,
        new DatabaseRefreshLock(DB::getFacadeRoot(), new SystemClock, 'oauth-corrections', 1),
        300,
    );

    expect($service->authorizationUrl('main', 'context'))
        ->toStartWith('https://mautic.example.test/marketing/oauth/v2/authorize?');

    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 3600,
        ], JSON_THROW_ON_ERROR)),
    ]));
    $stack->push(Middleware::history($history));
    $endpoint = new HttpOAuthTokenEndpoint(
        config(),
        correctionClient(new Client(['handler' => $stack])),
        correctionCredentials(),
        new SystemClock,
    );
    $endpoint->exchange('main', 'code');

    expect((string) $history[0]['request']->getUri())
        ->toBe('https://mautic.example.test/marketing/oauth/v2/token');
})->with([
    'root' => ['https://mautic.example.test/marketing'],
    'trailing slash' => ['https://mautic.example.test/marketing/'],
    'api suffix' => ['https://mautic.example.test/marketing/api'],
    'api suffix slash' => ['https://mautic.example.test/marketing/api/'],
]);

it('classifies safe token endpoint failures', function (Response|Throwable $failure, string $exception, ?string $kind, ?int $status, ?int $retryAfter) {
    $stack = HandlerStack::create(new MockHandler([$failure]));
    $endpoint = new HttpOAuthTokenEndpoint(
        config(),
        correctionClient(new Client(['handler' => $stack])),
        correctionCredentials(),
        new SystemClock,
    );

    try {
        $endpoint->refresh('main', new OAuthToken('old', 'refresh', 1));
        $this->fail('Expected token endpoint failure.');
    } catch (Throwable $caught) {
        expect($caught)->toBeInstanceOf($exception);
        if ($caught instanceof OAuthTokenRequestFailed) {
            expect($caught->kind->value)->toBe($kind)
                ->and($caught->status)->toBe($status)
                ->and($caught->retryAfterSeconds)->toBe($retryAfter)
                ->and($caught->getMessage())->not->toContain('client-secret', 'refresh');
        }
    }
})->with([
    'invalid grant' => [new Response(400, [], '{"error":"invalid_grant"}'), OAuthReauthorizationRequired::class, null, null, null],
    'invalid client' => [new Response(401, [], '{"error":"invalid_client"}'), OAuthTokenRequestFailed::class, 'invalid_client', 401, null],
    'throttled' => [new Response(429, ['Retry-After' => '17'], '{"error":"temporarily_unavailable"}'), OAuthTokenRequestFailed::class, 'throttled', 429, 17],
    'remote server' => [new Response(503, [], ''), OAuthTokenRequestFailed::class, 'remote_server', 503, null],
    'malformed error' => [new Response(400, [], '<html>secret upstream body</html>'), OAuthTokenRequestFailed::class, 'malformed_response', 400, null],
    'network' => [new ConnectException('sensitive transport detail', new Request('POST', 'https://mautic.example.test')), OAuthTokenRequestFailed::class, 'network', null, null],
]);

it('requires fresh authorization when an authorization code is an invalid grant', function () {
    $endpoint = new HttpOAuthTokenEndpoint(
        config(),
        correctionClient(new Client(['handler' => new MockHandler([
            new Response(400, [], '{"error":"invalid_grant"}'),
        ])])),
        correctionCredentials(),
        new SystemClock,
    );

    expect(fn () => $endpoint->exchange('main', 'expired-code'))
        ->toThrow(OAuthReauthorizationRequired::class);
});

it('prevents an expired lock owner from overwriting a newer token generation', function () {
    $clock = new class implements Clock
    {
        public int $timestamp = 100;

        public function now(): int
        {
            return $this->timestamp;
        }
    };
    $repository = new DatabaseOAuthTokenRepository(DB::getFacadeRoot(), Crypt::getFacadeRoot(), 'oauth-corrections');
    expect($repository->persist('main', new OAuthToken('expired', 'refresh-1', 1), null))->toBeTrue();
    $lock = new DatabaseRefreshLock(DB::getFacadeRoot(), $clock, 'oauth-corrections', 1);
    $endpoint = new class($clock) implements OAuthTokenEndpoint
    {
        public int $refreshes = 0;

        public ?OAuthTokenManager $competitor = null;

        public function __construct(private readonly object $clock) {}

        public function exchange(string $connection, string $code): OAuthToken
        {
            throw new LogicException('Not used.');
        }

        public function refresh(string $connection, OAuthToken $token): OAuthToken
        {
            $this->refreshes++;
            if ($this->refreshes === 1) {
                $this->clock->timestamp = 106;
                $this->competitor?->validToken($connection);

                return new OAuthToken('stale-owner', 'stale-refresh', 10_000);
            }

            return new OAuthToken('new-owner', 'new-refresh', 10_000);
        }
    };
    $original = new OAuthTokenManager($repository, $endpoint, $lock, $clock, 0, 2, 5);
    $endpoint->competitor = new OAuthTokenManager($repository, $endpoint, $lock, $clock, 0, 2, 5);

    $result = $original->validToken('main');

    expect($endpoint->refreshes)->toBe(2)
        ->and($result->accessToken)->toBe('new-owner')
        ->and($repository->record('main')?->generation)->toBe(2)
        ->and($repository->get('main')?->accessToken)->toBe('new-owner');
});

it('coordinates callback completion with an expired in-flight refresh', function () {
    config()->set('mautic.connections.main.timeout', 1);
    config()->set('mautic.connections.main.connect_timeout', 1);
    $clock = new class implements Clock
    {
        public int $timestamp = 100;

        public function now(): int
        {
            return $this->timestamp;
        }
    };
    $repository = new DatabaseOAuthTokenRepository(DB::getFacadeRoot(), Crypt::getFacadeRoot(), 'oauth-corrections');
    expect($repository->persist('main', new OAuthToken('expired', 'refresh-1', 1), null))->toBeTrue();
    $states = new DatabaseOAuthStateRepository(DB::getFacadeRoot(), $clock, 'oauth-corrections');
    $lock = new DatabaseRefreshLock(DB::getFacadeRoot(), $clock, 'oauth-corrections', 1);
    $endpoint = new class($clock) implements OAuthTokenEndpoint
    {
        public ?OAuth2Service $authorization = null;

        public string $state = '';

        public function __construct(private readonly object $clock) {}

        public function exchange(string $connection, string $code): OAuthToken
        {
            return new OAuthToken('callback-token', 'callback-refresh', 10_000);
        }

        public function refresh(string $connection, OAuthToken $token): OAuthToken
        {
            $this->clock->timestamp = 106;
            $this->authorization?->complete($connection, $this->state, 'callback-code', 'browser-flow');

            return new OAuthToken('stale-refresh-token', 'stale-refresh', 10_000);
        }
    };
    $authorization = new OAuth2Service(config(), $states, $repository, $endpoint, $lock, 300, 2, 5);
    $endpoint->authorization = $authorization;
    $url = $authorization->authorizationUrl('main', 'browser-flow');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $endpoint->state = (string) $query['state'];
    $manager = new OAuthTokenManager($repository, $endpoint, $lock, $clock, 0, 2, 5);

    $result = $manager->validToken('main');

    expect($result->accessToken)->toBe('callback-token')
        ->and($repository->record('main')?->generation)->toBe(2)
        ->and($repository->get('main')?->accessToken)->toBe('callback-token');
});

it('does not retry after remote rotation when persistence fails', function () {
    $repository = new class implements OAuthTokenRepository
    {
        public function get(string $connection): ?OAuthToken
        {
            return $this->record($connection)?->token;
        }

        public function record(string $connection): ?OAuthTokenRecord
        {
            return new OAuthTokenRecord(new OAuthToken('expired', 'refresh', 1), 1);
        }

        public function persist(string $connection, OAuthToken $token, ?int $expectedGeneration): bool
        {
            throw new RuntimeException('database unavailable');
        }
    };
    $endpoint = new class implements OAuthTokenEndpoint
    {
        public int $refreshes = 0;

        public function exchange(string $connection, string $code): OAuthToken
        {
            throw new LogicException;
        }

        public function refresh(string $connection, OAuthToken $token): OAuthToken
        {
            $this->refreshes++;

            return new OAuthToken('rotated', 'rotated-refresh', time() + 3600);
        }
    };
    $lock = new class implements OAuthRefreshLock
    {
        public function block(string $connection, int $waitSeconds, int $leaseSeconds, Closure $callback): mixed
        {
            return $callback();
        }
    };
    $manager = new OAuthTokenManager($repository, $endpoint, $lock, new SystemClock, 0, 1, 30);

    expect(fn () => $manager->validToken('main'))->toThrow(OAuthTokenPersistenceUncertain::class)
        ->and($endpoint->refreshes)->toBe(1);
});

it('preserves typed pre-request OAuth failures through real SDK reads and writes', function (string $expected, string $mode) {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([]));
    $stack->push(Middleware::history($history));
    $client = new Client(['handler' => $stack]);
    $repository = new class($mode) implements OAuthTokenRepository
    {
        public function __construct(private readonly string $mode) {}

        public function get(string $connection): ?OAuthToken
        {
            return $this->record($connection)?->token;
        }

        public function record(string $connection): ?OAuthTokenRecord
        {
            if ($this->mode === 'pre-request') {
                throw new RuntimeException('sensitive storage failure');
            }

            return $this->mode === 'missing'
                ? null
                : new OAuthTokenRecord(new OAuthToken('expired', 'refresh', 1), 1);
        }

        public function persist(string $connection, OAuthToken $token, ?int $expectedGeneration): bool
        {
            return true;
        }
    };
    $endpoint = new class($mode) implements OAuthTokenEndpoint
    {
        public function __construct(private readonly string $mode) {}

        public function exchange(string $connection, string $code): OAuthToken
        {
            throw new LogicException;
        }

        public function refresh(string $connection, OAuthToken $token): OAuthToken
        {
            if ($this->mode === 'revoked') {
                throw new OAuthReauthorizationRequired($connection, 400);
            }

            throw new OAuthTokenRequestFailed(
                $connection,
                OAuthTokenFailureKind::Network,
            );
        }
    };
    $lock = new class($mode) implements OAuthRefreshLock
    {
        public function __construct(private readonly string $mode) {}

        public function block(string $connection, int $waitSeconds, int $leaseSeconds, Closure $callback): mixed
        {
            if ($this->mode === 'lock') {
                throw new OAuthLockTimeout($connection);
            }

            return $callback();
        }
    };
    $manager = new OAuthTokenManager($repository, $endpoint, $lock, new SystemClock, 0, 1, 30);
    $auth = new ManagedOAuthAuth('main', $client, $manager);
    $connection = new MauticConnection('main', 'https://mautic.example.test/api/', $auth);

    foreach ([
        fn () => $connection->contactReads()->find(42),
        fn () => $connection->contactWrites()->update(
            42,
            ContactFields::fromArray(['firstname' => 'Ada'], ['firstname']),
        ),
    ] as $operation) {
        try {
            $operation();
            $this->fail('Expected OAuth authentication failure.');
        } catch (Throwable $exception) {
            expect($exception)->toBeInstanceOf($expected)
                ->not->toBeInstanceOf(AmbiguousMutationOutcome::class)
                ->and($exception->getMessage())->not->toContain('sensitive storage failure');
        }
    }

    expect($history)->toBeEmpty();
})->with([
    'missing authorization' => [OAuthReauthorizationRequired::class, 'missing'],
    'revoked refresh' => [OAuthReauthorizationRequired::class, 'revoked'],
    'lock timeout' => [OAuthLockTimeout::class, 'lock'],
    'token endpoint unavailable' => [OAuthTokenRequestFailed::class, 'endpoint'],
    'other pre-request failure' => [OAuthPreRequestFailed::class, 'pre-request'],
]);
