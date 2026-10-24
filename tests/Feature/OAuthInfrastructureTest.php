<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Env;
use Psr\Http\Client\ClientInterface;
use Trianity\LaravelMautic\Configuration\ConnectionConfig;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Contracts\HttpClientFactory;
use Trianity\LaravelMautic\Contracts\OAuthBrowserAuthorizer;
use Trianity\LaravelMautic\Contracts\OAuthClientCredentialsProvider;
use Trianity\LaravelMautic\Contracts\OAuthRefreshLock;
use Trianity\LaravelMautic\Contracts\OAuthStateRepository;
use Trianity\LaravelMautic\Contracts\OAuthTokenEndpoint;
use Trianity\LaravelMautic\Contracts\OAuthTokenRepository;
use Trianity\LaravelMautic\Data\OAuthClientCredentials;
use Trianity\LaravelMautic\Exceptions\InvalidConfiguration;
use Trianity\LaravelMautic\MauticServiceProvider;
use Trianity\LaravelMautic\OAuth\EnvironmentOAuthClientCredentialsProvider;
use Trianity\LaravelMautic\OAuth\HttpOAuthTokenEndpoint;
use Trianity\LaravelMautic\OAuth\OAuth2Service;
use Trianity\LaravelMautic\OAuth\OAuthCallbackUrlResolver;

afterEach(function (): void {
    Env::getRepository()->clear('MAUTIC_TEST_LARAVEL_SECRET');
    putenv('MAUTIC_TEST_PROCESS_SECRET');
});

it('resolves OAuth secrets from Laravel environment storage and the process environment', function (): void {
    config()->set('mautic.connections.main.oauth', [
        'client_id' => 'client-id',
        'client_secret_env' => 'MAUTIC_TEST_LARAVEL_SECRET',
    ]);
    Env::getRepository()->set('MAUTIC_TEST_LARAVEL_SECRET', 'laravel-secret');

    $provider = app(OAuthClientCredentialsProvider::class);

    expect($provider)->toBeInstanceOf(EnvironmentOAuthClientCredentialsProvider::class)
        ->and($provider->forConnection('main')->clientSecret)->toBe('laravel-secret');

    Env::getRepository()->clear('MAUTIC_TEST_LARAVEL_SECRET');
    putenv('MAUTIC_TEST_PROCESS_SECRET=process-secret');
    config()->set('mautic.connections.main.oauth.client_secret_env', 'MAUTIC_TEST_PROCESS_SECRET');

    expect($provider->forConnection('main')->clientSecret)->toBe('process-secret');
});

it('reports missing OAuth credentials without exposing configured values', function (): void {
    config()->set('mautic.connections.main.oauth', [
        'client_id' => 'sensitive-client-id',
        'client_secret_env' => 'MISSING_SENSITIVE_SECRET_NAME',
    ]);

    try {
        app(OAuthClientCredentialsProvider::class)->forConnection('main');
        $this->fail('Expected unavailable OAuth credentials.');
    } catch (InvalidConfiguration $exception) {
        expect($exception->getMessage())
            ->toContain('unavailable')
            ->not->toContain('sensitive-client-id', 'MISSING_SENSITIVE_SECRET_NAME');
    }
});

it('preserves a host-provided OAuth credentials binding and denies browser OAuth by default', function (): void {
    $custom = Mockery::mock(OAuthClientCredentialsProvider::class);
    app()->instance(OAuthClientCredentialsProvider::class, $custom);

    $provider = new MauticServiceProvider(app());
    $provider->register();

    expect(app(OAuthClientCredentialsProvider::class))->toBe($custom)
        ->and(app(OAuthBrowserAuthorizer::class)->allows(request(), 'main'))->toBeFalse();
});

it('does not register browser OAuth routes by default', function (): void {
    expect(app('router')->has('mautic.oauth.start'))->toBeFalse()
        ->and(app('router')->has('mautic.oauth.callback'))->toBeFalse();
});

it('resolves one canonical callback URL with explicit configuration taking precedence', function (): void {
    config()->set('app.url', 'https://crm.example.test/base');
    config()->set('mautic.oauth.routes', [
        'enabled' => true,
        'prefix' => 'integrations/mautic/oauth',
        'connections' => ['main'],
    ]);
    config()->set('mautic.connections.main', [
        'url' => 'https://mautic.example.test',
        'auth' => 'oauth2',
        'oauth' => [
            'client_id' => 'client-id',
            'client_secret_env' => 'MAUTIC_TEST_SECRET',
        ],
    ]);

    $resolver = app(OAuthCallbackUrlResolver::class);

    expect($resolver->resolve('main'))
        ->toBe('https://crm.example.test/base/integrations/mautic/oauth/main/callback');

    config()->set('mautic.connections.main.oauth.redirect_uri', 'https://explicit.example.test/oauth/callback');

    expect($resolver->resolve('main'))
        ->toBe('https://explicit.example.test/oauth/callback');
});

it('rejects derived callback URLs when package routes are disabled or the connection is not allowed', function (): void {
    config()->set('app.url', 'https://crm.example.test');
    config()->set('mautic.connections.main', [
        'url' => 'https://mautic.example.test',
        'auth' => 'oauth2',
        'oauth' => [
            'client_id' => 'client-id',
            'client_secret_env' => 'MAUTIC_TEST_SECRET',
        ],
    ]);

    expect(fn () => app(OAuthCallbackUrlResolver::class)->resolve('main'))
        ->toThrow(InvalidConfiguration::class, 'callback URL');

    config()->set('mautic.oauth.routes.enabled', true);
    config()->set('mautic.oauth.routes.connections', ['second']);

    expect(fn () => app(OAuthCallbackUrlResolver::class)->resolve('main'))
        ->toThrow(InvalidConfiguration::class, 'allowlisted');

    config()->set('mautic.oauth.routes.connections', ['main']);
    config()->set('mautic.oauth.routes.prefix', '../callback');

    expect(fn () => app(OAuthCallbackUrlResolver::class)->resolve('main'))
        ->toThrow(InvalidConfiguration::class, 'prefix');
});

it('uses the same derived callback URL for authorization and token exchange', function (): void {
    config()->set('app.url', 'https://crm.example.test/base');
    config()->set('mautic.oauth.routes', [
        'enabled' => true,
        'prefix' => 'integrations/mautic/oauth',
        'connections' => ['main'],
    ]);
    config()->set('mautic.connections.main', [
        'url' => 'https://mautic.example.test',
        'auth' => 'oauth2',
        'oauth' => [
            'client_id' => 'client-id',
            'client_secret_env' => 'MAUTIC_TEST_SECRET',
        ],
    ]);
    $resolver = app(OAuthCallbackUrlResolver::class);
    $states = Mockery::mock(OAuthStateRepository::class);
    $states->expects('issue')->with('main', 'browser-context', 600)->andReturn('issued-state');
    $service = new OAuth2Service(
        config(),
        $states,
        Mockery::mock(OAuthTokenRepository::class),
        Mockery::mock(OAuthTokenEndpoint::class),
        Mockery::mock(OAuthRefreshLock::class),
        callbackUrls: $resolver,
    );
    parse_str((string) parse_url($service->authorizationUrl('main', 'browser-context'), PHP_URL_QUERY), $authorization);

    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'expires_in' => 3600,
        ], JSON_THROW_ON_ERROR)),
    ]));
    $stack->push(Middleware::history($history));
    $clients = new class(new Client(['handler' => $stack])) implements HttpClientFactory
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
    $clock = new class implements Clock
    {
        public function now(): int
        {
            return 1_000;
        }
    };
    (new HttpOAuthTokenEndpoint(config(), $clients, $credentials, $clock, $resolver))->exchange('main', 'code');
    parse_str((string) $history[0]['request']->getBody(), $tokenRequest);

    expect($authorization['redirect_uri'])->toBe($resolver->resolve('main'))
        ->and($tokenRequest['redirect_uri'])->toBe($authorization['redirect_uri']);
});

it('prints the canonical callback URL without OAuth exchange or mutation', function (): void {
    config()->set('mautic.connections.main', [
        'url' => 'https://mautic.example.test',
        'auth' => 'oauth2',
        'oauth' => [
            'client_id' => 'client-id',
            'client_secret_env' => 'MAUTIC_TEST_SECRET',
            'redirect_uri' => 'https://host.example.test/domain/mautic/callback',
        ],
    ]);

    $this->artisan('mautic:oauth:callback-url', ['connection' => 'main'])
        ->expectsOutputToContain('https://host.example.test/domain/mautic/callback')
        ->expectsOutputToContain('package OAuth routes are disabled')
        ->assertSuccessful();
});
