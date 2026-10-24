<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Tests\Integration;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Trianity\LaravelMautic\Contracts\OAuthBrowserAuthorizer;
use Trianity\LaravelMautic\Contracts\OAuthRefreshLock;
use Trianity\LaravelMautic\Contracts\OAuthStateRepository;
use Trianity\LaravelMautic\Contracts\OAuthTokenEndpoint;
use Trianity\LaravelMautic\Contracts\OAuthTokenRepository;
use Trianity\LaravelMautic\Data\OAuthToken;
use Trianity\LaravelMautic\Data\OAuthTokenRecord;
use Trianity\LaravelMautic\MauticServiceProvider;
use Trianity\LaravelMautic\OAuth\DenyOAuthBrowserAuthorizer;
use Trianity\LaravelMautic\OAuth\OAuth2Service;
use Trianity\LaravelMautic\OAuth\OAuthCallbackUrlResolver;

final class AllowOAuthBrowserAuthorizer implements OAuthBrowserAuthorizer
{
    public bool $allowed = true;

    public function allows(Request $request, string $connection): bool
    {
        return $this->allowed;
    }
}

final class BrowserStateRepository implements OAuthStateRepository
{
    /** @var array<string, array{connection: string, context: string}> */
    public array $states = [];

    public function issue(string $connection, string $authorizationContext, int $ttlSeconds): string
    {
        $state = 'state-'.count($this->states);
        $this->states[$state] = ['connection' => $connection, 'context' => $authorizationContext];

        return $state;
    }

    public function consume(string $connection, string $state, string $authorizationContext): bool
    {
        $stored = $this->states[$state] ?? null;
        if ($stored === null || $stored !== ['connection' => $connection, 'context' => $authorizationContext]) {
            return false;
        }

        unset($this->states[$state]);

        return true;
    }
}

final class BrowserTokenRepository implements OAuthTokenRepository
{
    public ?OAuthToken $token = null;

    public function get(string $connection): ?OAuthToken
    {
        return $this->token;
    }

    public function record(string $connection): ?OAuthTokenRecord
    {
        return $this->token === null ? null : new OAuthTokenRecord($this->token, 1);
    }

    public function persist(string $connection, OAuthToken $token, ?int $expectedGeneration): bool
    {
        $this->token = $token;

        return true;
    }
}

final class BrowserTokenEndpoint implements OAuthTokenEndpoint
{
    public bool $fail = false;

    /** @var list<string> */
    public array $codes = [];

    public function exchange(string $connection, string $code): OAuthToken
    {
        $this->codes[] = $code;
        if ($this->fail) {
            throw new RuntimeException('sensitive-code secret-token');
        }

        return new OAuthToken('access', 'refresh', time() + 3600);
    }

    public function refresh(string $connection, OAuthToken $token): OAuthToken
    {
        throw new RuntimeException('Not used.');
    }
}

final readonly class BrowserRefreshLock implements OAuthRefreshLock
{
    public function block(string $connection, int $waitSeconds, int $leaseSeconds, Closure $callback): mixed
    {
        return $callback();
    }
}

final class OAuthBrowserRoutesTest extends TestCase
{
    private AllowOAuthBrowserAuthorizer $authorizer;

    private BrowserStateRepository $states;

    private BrowserTokenRepository $tokens;

    private BrowserTokenEndpoint $endpoint;

    protected function getPackageProviders($app): array
    {
        return [MauticServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('r', 32)));
        $app['config']->set('app.url', 'https://host.example.test/base');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('mautic.oauth.routes', [
            'enabled' => true,
            'prefix' => 'integrations/mautic/oauth',
            'start_name' => 'host.mautic.oauth.start',
            'callback_name' => 'host.mautic.oauth.callback',
            'middleware' => ['web'],
            'connections' => ['main'],
            'result_route' => 'host.mautic.oauth.result',
        ]);
        $app['config']->set('mautic.connections.main', [
            'url' => 'https://mautic.example.test',
            'auth' => 'oauth2',
            'timeout' => 1,
            'connect_timeout' => 1,
            'oauth' => [
                'client_id' => 'client-id',
                'client_secret_env' => 'MAUTIC_TEST_SECRET',
                'scopes' => [],
            ],
        ]);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/oauth-result', static fn (): string => 'result')->name('host.mautic.oauth.result');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->authorizer = new AllowOAuthBrowserAuthorizer;
        $this->states = new BrowserStateRepository;
        $this->tokens = new BrowserTokenRepository;
        $this->endpoint = new BrowserTokenEndpoint;
        $this->app->instance(OAuthBrowserAuthorizer::class, $this->authorizer);
        $this->app->instance(OAuth2Service::class, new OAuth2Service(
            $this->app->make(Repository::class),
            $this->states,
            $this->tokens,
            $this->endpoint,
            new BrowserRefreshLock,
            600,
            5,
            30,
            $this->app->make(OAuthCallbackUrlResolver::class),
        ));
    }

    public function test_routes_use_configured_prefix_names_and_connection_allowlist(): void
    {
        self::assertSame(
            'integrations/mautic/oauth/{connection}/authorize',
            $this->app['router']->getRoutes()->getByName('host.mautic.oauth.start')?->uri(),
        );
        self::assertSame(
            'integrations/mautic/oauth/{connection}/callback',
            $this->app['router']->getRoutes()->getByName('host.mautic.oauth.callback')?->uri(),
        );
        self::assertContains(
            'web',
            $this->app['router']->getRoutes()->getByName('host.mautic.oauth.start')?->gatherMiddleware() ?? [],
        );

        $this->post('/integrations/mautic/oauth/other/authorize')->assertNotFound();
    }

    public function test_missing_or_denied_host_authorization_cannot_start_oauth(): void
    {
        $this->app->instance(OAuthBrowserAuthorizer::class, new DenyOAuthBrowserAuthorizer);
        $this->post('/integrations/mautic/oauth/main/authorize')->assertForbidden();

        $this->app->instance(OAuthBrowserAuthorizer::class, $this->authorizer);
        $this->authorizer->allowed = false;

        $this->post('/integrations/mautic/oauth/main/authorize')->assertForbidden();

        self::assertSame([], $this->states->states);
    }

    public function test_session_context_completes_once_and_returns_only_sanitized_results(): void
    {
        $start = $this->withHeader('Host', 'attacker.example.test')
            ->post('/integrations/mautic/oauth/main/authorize')
            ->assertRedirect();
        parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);
        self::assertSame(
            'https://host.example.test/base/integrations/mautic/oauth/main/callback',
            $query['redirect_uri'],
        );

        $this->get('/integrations/mautic/oauth/main/callback?state='.$query['state'].'&code=one-time-code')
            ->assertRedirect('/oauth-result')
            ->assertSessionHas('mautic_oauth.status', 'authorized');

        self::assertSame(['one-time-code'], $this->endpoint->codes);

        $this->get('/integrations/mautic/oauth/main/callback?state='.$query['state'].'&code=replay')
            ->assertRedirect('/oauth-result')
            ->assertSessionHas('mautic_oauth.status', 'failed');

        self::assertSame(['one-time-code'], $this->endpoint->codes);
    }

    public function test_denial_malformed_callback_and_exchange_failure_are_sanitized(): void
    {
        $this->post('/integrations/mautic/oauth/main/authorize');
        $this->withHeader('Host', 'attacker.example.test')
            ->get('/integrations/mautic/oauth/main/callback?error=access_denied&error_description=secret&redirect=https://attacker.example.test')
            ->assertRedirect('/oauth-result')
            ->assertSessionHas('mautic_oauth.reason', 'provider_denied')
            ->assertSessionMissing('error_description');

        $this->post('/integrations/mautic/oauth/main/authorize');
        $this->get('/integrations/mautic/oauth/main/callback?state=missing-code')
            ->assertSessionHas('mautic_oauth.reason', 'invalid_callback');

        $start = $this->post('/integrations/mautic/oauth/main/authorize');
        parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->endpoint->fail = true;
        $response = $this->get('/integrations/mautic/oauth/main/callback?state='.$query['state'].'&code=sensitive-code')
            ->assertSessionHas('mautic_oauth.reason', 'authorization_failed');

        self::assertStringNotContainsString('sensitive-code', (string) $response->getContent());
        self::assertStringNotContainsString('secret-token', (string) $response->getContent());
    }

    public function test_repeated_starts_replace_the_connection_session_context_safely(): void
    {
        $first = $this->post('/integrations/mautic/oauth/main/authorize');
        $second = $this->post('/integrations/mautic/oauth/main/authorize');
        parse_str((string) parse_url((string) $first->headers->get('Location'), PHP_URL_QUERY), $firstQuery);
        parse_str((string) parse_url((string) $second->headers->get('Location'), PHP_URL_QUERY), $secondQuery);

        self::assertNotSame($firstQuery['state'], $secondQuery['state']);

        $this->get('/integrations/mautic/oauth/main/callback?state='.$firstQuery['state'].'&code=old')
            ->assertSessionHas('mautic_oauth.status', 'failed');
        self::assertSame([], $this->endpoint->codes);
    }
}
