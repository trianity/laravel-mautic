<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Http\Controllers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use Throwable;
use Trianity\LaravelMautic\Contracts\OAuthBrowserAuthorizer;
use Trianity\LaravelMautic\Exceptions\InvalidOAuthState;
use Trianity\LaravelMautic\OAuth\OAuth2Service;
use Trianity\LaravelMautic\OAuth\OAuthCallbackUrlResolver;

final readonly class OAuthBrowserController
{
    public function start(
        string $connection,
        Request $request,
        Repository $config,
        OAuthBrowserAuthorizer $authorizer,
        OAuthCallbackUrlResolver $callbacks,
        OAuth2Service $oauth,
        Router $router,
        UrlGenerator $urls,
    ): RedirectResponse|Response {
        $this->guard($request, $connection, $authorizer, $callbacks);

        try {
            $this->assertResultRoute($config, $router);
            $context = bin2hex(random_bytes(32));
            $authorizationUrl = $oauth->authorizationUrl($connection, $context);
            $request->session()->put($this->sessionKey($connection), $context);

            return redirect()->away($authorizationUrl);
        } catch (Throwable) {
            return $this->result($config, $router, $urls, $connection, 'failed', 'authorization_start_failed', 422);
        }
    }

    public function callback(
        string $connection,
        Request $request,
        Repository $config,
        OAuthBrowserAuthorizer $authorizer,
        OAuthCallbackUrlResolver $callbacks,
        OAuth2Service $oauth,
        Router $router,
        UrlGenerator $urls,
    ): RedirectResponse|Response {
        $this->guard($request, $connection, $authorizer, $callbacks);

        $sessionKey = $this->sessionKey($connection);
        $context = $request->session()->get($sessionKey);
        if (! is_string($context) || $context === '') {
            return $this->result($config, $router, $urls, $connection, 'failed', 'session_missing', 400);
        }

        $state = $request->query('state');
        $code = $request->query('code');
        if ($request->filled('error')) {
            return $this->result($config, $router, $urls, $connection, 'failed', 'provider_denied', 400);
        }
        if (! is_string($state) || $state === '' || strlen($state) > 255
            || ! is_string($code) || $code === '' || strlen($code) > 2048) {
            return $this->result($config, $router, $urls, $connection, 'failed', 'invalid_callback', 400);
        }

        try {
            $oauth->complete($connection, $state, $code, $context);
        } catch (InvalidOAuthState) {
            return $this->result($config, $router, $urls, $connection, 'failed', 'authorization_failed', 400);
        } catch (Throwable) {
            $request->session()->forget($sessionKey);

            return $this->result($config, $router, $urls, $connection, 'failed', 'authorization_failed', 400);
        }

        $request->session()->forget($sessionKey);

        return $this->result($config, $router, $urls, $connection, 'authorized', null, 200);
    }

    private function guard(
        Request $request,
        string $connection,
        OAuthBrowserAuthorizer $authorizer,
        OAuthCallbackUrlResolver $callbacks,
    ): void {
        abort_unless($callbacks->connectionIsAllowed($connection), 404);
        abort_unless($authorizer->allows($request, $connection), 403);
    }

    private function sessionKey(string $connection): string
    {
        return 'mautic.oauth.browser.'.hash('sha256', $connection);
    }

    private function assertResultRoute(Repository $config, Router $router): void
    {
        $route = $config->get('mautic.oauth.routes.result_route');
        if ($route !== null && (! is_string($route) || $route === '' || ! $router->has($route))) {
            throw new \InvalidArgumentException('The configured OAuth result route is unavailable.');
        }
    }

    private function result(
        Repository $config,
        Router $router,
        UrlGenerator $urls,
        string $connection,
        string $status,
        ?string $reason,
        int $httpStatus,
    ): RedirectResponse|Response {
        $payload = array_filter([
            'status' => $status,
            'connection' => $connection,
            'reason' => $reason,
        ], static fn (?string $value): bool => $value !== null);
        $route = $config->get('mautic.oauth.routes.result_route');

        if (is_string($route) && $route !== '' && $router->has($route)) {
            return redirect()->away($urls->route($route, [], false))->with('mautic_oauth', $payload);
        }

        return response($status === 'authorized' ? 'OAuth authorization completed.' : 'OAuth authorization failed.', $httpStatus);
    }
}
