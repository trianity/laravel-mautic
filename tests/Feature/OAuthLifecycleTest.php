<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
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
use Trianity\LaravelMautic\Exceptions\InvalidOAuthState;
use Trianity\LaravelMautic\Exceptions\OAuthReauthorizationRequired;
use Trianity\LaravelMautic\MauticManager;
use Trianity\LaravelMautic\OAuth\DatabaseOAuthStateRepository;
use Trianity\LaravelMautic\OAuth\DatabaseOAuthTokenRepository;
use Trianity\LaravelMautic\OAuth\DatabaseRefreshLock;
use Trianity\LaravelMautic\OAuth\HttpOAuthTokenEndpoint;
use Trianity\LaravelMautic\OAuth\OAuth2Service;
use Trianity\LaravelMautic\OAuth\OAuthTokenManager;
use Trianity\LaravelMautic\OAuth\SystemClock;
use Trianity\LaravelMautic\Support\ApiLibraryAuthenticationFactory;
use Trianity\LaravelMautic\Support\ApiLibraryConnectionFactory;

beforeEach(function () {
    config()->set('database.default', 'oauth-test');
    config()->set('database.connections.oauth-test', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    DB::purge('oauth-test');

    Schema::connection('oauth-test')->create('mautic_oauth_tokens', function (Blueprint $table): void {
        $table->string('connection')->primary();
        $table->text('token_payload');
        $table->unsignedBigInteger('expires_at');
        $table->unsignedBigInteger('generation');
        $table->timestamps();
    });
    Schema::connection('oauth-test')->create('mautic_oauth_states', function (Blueprint $table): void {
        $table->string('state_hash', 64)->primary();
        $table->string('connection');
        $table->string('context_hash', 64);
        $table->unsignedBigInteger('expires_at');
        $table->timestamp('created_at');
    });
    Schema::connection('oauth-test')->create('mautic_oauth_locks', function (Blueprint $table): void {
        $table->string('name')->primary();
        $table->string('owner', 64);
        $table->unsignedBigInteger('expires_at');
    });
});

it('encrypts durable connection-scoped tokens and replaces rotations atomically', function () {
    $repository = new DatabaseOAuthTokenRepository(DB::getFacadeRoot(), Crypt::getFacadeRoot(), 'oauth-test');
    $first = new OAuthToken('access-one', 'refresh-one', 2_000_000_000);
    $second = new OAuthToken('access-two', 'refresh-two', 2_000_000_100);

    expect($repository->persist('main', $first, null))->toBeTrue()
        ->and($repository->persist('second', $second, null))->toBeTrue();

    $raw = DB::connection('oauth-test')->table('mautic_oauth_tokens')->where('connection', 'main')->value('token_payload');

    expect($raw)->toBeString()
        ->and($raw)->not->toContain('access-one', 'refresh-one')
        ->and($repository->get('main'))->toEqual($first)
        ->and($repository->get('second'))->toEqual($second);

    expect($repository->persist('main', $second, 1))->toBeTrue();

    expect($repository->get('main'))->toEqual($second)
        ->and(DB::connection('oauth-test')->table('mautic_oauth_tokens')->where('connection', 'main')->count())->toBe(1);
});

it('issues expiring one-time state bound to a connection', function () {
    $states = new DatabaseOAuthStateRepository(DB::getFacadeRoot(), new SystemClock, 'oauth-test');
    $state = $states->issue('main', 'browser-flow', 300);

    expect($states->consume('second', $state, 'browser-flow'))->toBeFalse()
        ->and($states->consume('main', $state, 'browser-flow'))->toBeTrue()
        ->and($states->consume('main', $state, 'browser-flow'))->toBeFalse();
});

it('rejects expired state', function () {
    $clock = new class implements Clock
    {
        public int $timestamp = 1_000;

        public function now(): int
        {
            return $this->timestamp;
        }
    };
    $states = new DatabaseOAuthStateRepository(DB::getFacadeRoot(), $clock, 'oauth-test');
    $state = $states->issue('main', 'browser-flow', 60);
    $clock->timestamp = 1_061;

    expect($states->consume('main', $state, 'browser-flow'))->toBeFalse();
});

it('re-reads under the refresh lock and saves rotation before releasing it', function () {
    $repository = new class implements OAuthTokenRepository
    {
        public OAuthToken $token;

        public int $reads = 0;

        public bool $savedInsideLock = false;

        public function get(string $connection): ?OAuthToken
        {
            return $this->record($connection)?->token;
        }

        public function record(string $connection): ?OAuthTokenRecord
        {
            $this->reads++;

            return new OAuthTokenRecord($this->token, 1);
        }

        public function persist(string $connection, OAuthToken $token, ?int $expectedGeneration): bool
        {
            $this->token = $token;
            $this->savedInsideLock = true;

            return true;
        }
    };
    $repository->token = new OAuthToken('stale', 'refresh-old', 1);
    $endpoint = new class implements OAuthTokenEndpoint
    {
        public int $refreshes = 0;

        public function exchange(string $connection, string $code): OAuthToken
        {
            throw new LogicException('Not used.');
        }

        public function refresh(string $connection, OAuthToken $token): OAuthToken
        {
            $this->refreshes++;

            return new OAuthToken('fresh', 'refresh-rotated', time() + 3600);
        }
    };
    $lock = new class implements OAuthRefreshLock
    {
        public bool $releasedAfterSave = false;

        public function block(string $connection, int $waitSeconds, int $leaseSeconds, Closure $callback): mixed
        {
            $result = $callback();
            $this->releasedAfterSave = true;

            return $result;
        }
    };
    $manager = new OAuthTokenManager($repository, $endpoint, $lock, new SystemClock, 0, 2, 10);

    $token = $manager->validToken('main');

    expect($token->accessToken)->toBe('fresh')
        ->and($repository->reads)->toBe(2)
        ->and($endpoint->refreshes)->toBe(1)
        ->and($repository->savedInsideLock)->toBeTrue()
        ->and($lock->releasedAfterSave)->toBeTrue();
});

it('uses a token refreshed by another worker after acquiring the lock', function () {
    $fresh = new OAuthToken('other-worker', 'rotated', time() + 3600);
    $repository = new class($fresh) implements OAuthTokenRepository
    {
        public int $reads = 0;

        public function __construct(private readonly OAuthToken $fresh) {}

        public function get(string $connection): ?OAuthToken
        {
            return $this->record($connection)?->token;
        }

        public function record(string $connection): ?OAuthTokenRecord
        {
            $this->reads++;

            return new OAuthTokenRecord(
                $this->reads === 1 ? new OAuthToken('stale', 'old', 1) : $this->fresh,
                $this->reads,
            );
        }

        public function persist(string $connection, OAuthToken $token, ?int $expectedGeneration): bool
        {
            return true;
        }
    };
    $endpoint = new class implements OAuthTokenEndpoint
    {
        public int $refreshes = 0;

        public function exchange(string $connection, string $code): OAuthToken
        {
            throw new LogicException('Not used.');
        }

        public function refresh(string $connection, OAuthToken $token): OAuthToken
        {
            $this->refreshes++;
            throw new LogicException('Must not refresh twice.');
        }
    };
    $lock = new DatabaseRefreshLock(DB::getFacadeRoot(), new SystemClock, 'oauth-test', 1_000);
    $manager = new OAuthTokenManager($repository, $endpoint, $lock, new SystemClock, 0, 2, 10);

    expect($manager->validToken('main'))->toBe($fresh)
        ->and($repository->reads)->toBe(2)
        ->and($endpoint->refreshes)->toBe(0);
});

it('never redirects when authorization or a revoked refresh token is required', function () {
    $repository = new class implements OAuthTokenRepository
    {
        public function get(string $connection): ?OAuthToken
        {
            return null;
        }

        public function record(string $connection): ?OAuthTokenRecord
        {
            return null;
        }

        public function persist(string $connection, OAuthToken $token, ?int $expectedGeneration): bool
        {
            return true;
        }
    };
    $endpoint = new class implements OAuthTokenEndpoint
    {
        public function exchange(string $connection, string $code): OAuthToken
        {
            throw new LogicException;
        }

        public function refresh(string $connection, OAuthToken $token): OAuthToken
        {
            throw new OAuthReauthorizationRequired($connection);
        }
    };
    $manager = new OAuthTokenManager(
        $repository,
        $endpoint,
        new DatabaseRefreshLock(DB::getFacadeRoot(), new SystemClock, 'oauth-test', 1_000),
        new SystemClock,
        0,
        1,
        5,
    );

    expect(fn () => $manager->validToken('main'))->toThrow(OAuthReauthorizationRequired::class);
});

it('validates state before exchanging callback codes and stores the result', function () {
    $tokens = new DatabaseOAuthTokenRepository(DB::getFacadeRoot(), Crypt::getFacadeRoot(), 'oauth-test');
    $states = new DatabaseOAuthStateRepository(DB::getFacadeRoot(), new SystemClock, 'oauth-test');
    $endpoint = new class implements OAuthTokenEndpoint
    {
        public int $exchanges = 0;

        public function exchange(string $connection, string $code): OAuthToken
        {
            $this->exchanges++;

            return new OAuthToken('access', 'refresh', time() + 3600);
        }

        public function refresh(string $connection, OAuthToken $token): OAuthToken
        {
            throw new LogicException;
        }
    };
    config()->set('mautic.connections.main', [
        'url' => 'https://mautic.example.test',
        'auth' => 'oauth2',
        'oauth' => [
            'client_id' => 'client',
            'client_secret_env' => 'SECRET_NAME_ONLY',
            'redirect_uri' => 'https://app.example.test/callback',
        ],
    ]);
    config()->set('mautic.connections.second', config('mautic.connections.main'));
    $service = new OAuth2Service(
        config(),
        $states,
        $tokens,
        $endpoint,
        new DatabaseRefreshLock(DB::getFacadeRoot(), new SystemClock, 'oauth-test', 1),
        300,
    );
    $url = $service->authorizationUrl('main', 'browser-flow');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($query['state'] ?? null)->toBeString()
        ->and(fn () => $service->complete('second', (string) $query['state'], 'code', 'browser-flow'))
        ->toThrow(InvalidOAuthState::class);

    $token = $service->complete('main', (string) $query['state'], 'code', 'browser-flow');

    expect($endpoint->exchanges)->toBe(1)
        ->and($tokens->get('main'))->toEqual($token)
        ->and(fn () => $service->complete('main', (string) $query['state'], 'replay', 'browser-flow'))
        ->toThrow(InvalidOAuthState::class);
});

it('keeps basic auth contact requests unchanged', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], '{"contact":{"id":42,"fields":{"all":[]}}}'),
    ]));
    $stack->push(Middleware::history($history));
    $client = new Client(['handler' => $stack]);
    $config = ConnectionConfig::fromArray('main', [
        'url' => 'https://mautic.example.test', 'username' => 'user', 'password' => 'secret',
    ]);
    $factory = new ApiLibraryConnectionFactory(
        new class($client) implements HttpClientFactory
        {
            public function __construct(private readonly Client $client) {}

            public function make(ConnectionConfig $config): ClientInterface
            {
                return $this->client;
            }
        },
    );

    $factory->make($config)->contactReads()->find(42);

    expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('Basic '.base64_encode('user:secret'));
});

it('rotates tokens once and treats a rejected refresh as reauthorization', function () {
    config()->set('mautic.connections.main', [
        'url' => 'https://mautic.example.test',
        'auth' => 'oauth2',
        'oauth' => [
            'client_id' => 'client-id',
            'client_secret_env' => 'IGNORED_BY_TEST_PROVIDER',
            'redirect_uri' => 'https://app.example.test/callback',
        ],
    ]);
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => 'new-access',
            'refresh_token' => 'new-refresh',
            'expires_in' => 3600,
            'token_type' => 'bearer',
        ], JSON_THROW_ON_ERROR)),
        new Response(400, ['Content-Type' => 'application/json'], '{"error":"invalid_grant"}'),
    ]));
    $stack->push(Middleware::history($history));
    $client = new Client(['handler' => $stack]);
    $clients = new class($client) implements HttpClientFactory
    {
        public function __construct(private readonly Client $client) {}

        public function make(ConnectionConfig $config): ClientInterface
        {
            return $this->client;
        }
    };
    $credentials = new class implements OAuthClientCredentialsProvider
    {
        public function forConnection(string $connection): OAuthClientCredentials
        {
            return new OAuthClientCredentials('client-id', 'client-secret');
        }
    };
    $endpoint = new HttpOAuthTokenEndpoint(config(), $clients, $credentials, new SystemClock);
    $old = new OAuthToken('old-access', 'old-refresh', 1);

    $rotated = $endpoint->refresh('main', $old);

    expect($rotated->accessToken)->toBe('new-access')
        ->and($rotated->refreshToken)->toBe('new-refresh')
        ->and((string) $history[0]['request']->getBody())->toContain(
            'grant_type=refresh_token',
            'refresh_token=old-refresh',
        )
        ->and(fn () => $endpoint->refresh('main', $rotated))
        ->toThrow(OAuthReauthorizationRequired::class);

    expect($history)->toHaveCount(2);
});

it('uses managed OAuth bearer authentication for existing contact reads', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], '{"contact":{"id":42,"fields":{"all":[]}}}'),
    ]));
    $stack->push(Middleware::history($history));
    $client = new Client(['handler' => $stack]);
    $repository = new class implements OAuthTokenRepository
    {
        public function get(string $connection): ?OAuthToken
        {
            return new OAuthToken('access-token', 'refresh-token', time() + 3600);
        }

        public function record(string $connection): ?OAuthTokenRecord
        {
            return new OAuthTokenRecord($this->get($connection), 1);
        }

        public function persist(string $connection, OAuthToken $token, ?int $expectedGeneration): bool
        {
            return true;
        }
    };
    $endpoint = new class implements OAuthTokenEndpoint
    {
        public function exchange(string $connection, string $code): OAuthToken
        {
            throw new LogicException;
        }

        public function refresh(string $connection, OAuthToken $token): OAuthToken
        {
            throw new LogicException;
        }
    };
    $tokens = new OAuthTokenManager(
        $repository,
        $endpoint,
        new DatabaseRefreshLock(DB::getFacadeRoot(), new SystemClock, 'oauth-test', 1),
        new SystemClock,
    );
    $authentication = new ApiLibraryAuthenticationFactory($tokens);
    $factory = new ApiLibraryConnectionFactory(
        new class($client) implements HttpClientFactory
        {
            public function __construct(private readonly Client $client) {}

            public function make(ConnectionConfig $config): ClientInterface
            {
                return $this->client;
            }
        },
        $authentication,
    );
    $connection = ConnectionConfig::fromArray('main', [
        'url' => 'https://mautic.example.test',
        'auth' => 'oauth2',
        'oauth' => [
            'client_id' => 'client-id',
            'client_secret_env' => 'SECRET_NAME_ONLY',
            'redirect_uri' => 'https://app.example.test/callback',
        ],
    ]);

    $factory->make($connection)->contactReads()->find(42);

    expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer access-token');
});

it('resolves an OAuth connection through the provider and manager', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], '{"contact":{"id":42,"fields":{"all":[]}}}'),
    ]));
    $stack->push(Middleware::history($history));
    $client = new Client(['handler' => $stack]);
    $this->app->instance(HttpClientFactory::class, new class($client) implements HttpClientFactory
    {
        public function __construct(private readonly Client $client) {}

        public function make(ConnectionConfig $config): ClientInterface
        {
            return $this->client;
        }
    });
    $this->app->instance(OAuthTokenRepository::class, new class implements OAuthTokenRepository
    {
        public function get(string $connection): ?OAuthToken
        {
            return new OAuthToken('container-token', 'refresh-token', time() + 3600);
        }

        public function record(string $connection): ?OAuthTokenRecord
        {
            return new OAuthTokenRecord($this->get($connection), 1);
        }

        public function persist(string $connection, OAuthToken $token, ?int $expectedGeneration): bool
        {
            return true;
        }
    });
    config()->set('mautic.connections.main', [
        'url' => 'https://mautic.example.test',
        'auth' => 'oauth2',
        'oauth' => [
            'client_id' => 'client-id',
            'client_secret_env' => 'SECRET_NAME_ONLY',
            'redirect_uri' => 'https://app.example.test/callback',
        ],
    ]);

    app(MauticManager::class)->connection()->contactReads()->find(42);

    expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer container-token');
});
