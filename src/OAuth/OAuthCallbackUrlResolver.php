<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\OAuth;

use Illuminate\Contracts\Config\Repository;
use Trianity\LaravelMautic\Configuration\ConnectionConfig;
use Trianity\LaravelMautic\Exceptions\InvalidConfiguration;

final readonly class OAuthCallbackUrlResolver
{
    public function __construct(private Repository $config) {}

    public function resolve(string $connection): string
    {
        $values = $this->config->get("mautic.connections.{$connection}");
        $oauth = is_array($values) ? ($values['oauth'] ?? null) : null;
        if (! is_array($values) || ! is_array($oauth)) {
            throw new InvalidConfiguration('The requested Mautic OAuth2 connection is not configured.');
        }
        if (ConnectionConfig::fromArray($connection, $values)->auth !== 'oauth2') {
            throw new InvalidConfiguration('The requested Mautic connection does not use OAuth2.');
        }

        $explicit = $oauth['redirect_uri'] ?? null;
        if ($explicit !== null && (! is_string($explicit) || trim($explicit) === '')) {
            throw new InvalidConfiguration('The configured OAuth2 callback URL is invalid.');
        }
        if (is_string($explicit)) {
            if (filter_var($explicit, FILTER_VALIDATE_URL) === false) {
                throw new InvalidConfiguration('The configured OAuth2 callback URL is invalid.');
            }

            return $explicit;
        }

        if ($this->config->get('mautic.oauth.routes.enabled', false) !== true) {
            throw new InvalidConfiguration('The OAuth2 callback URL is unavailable because package OAuth routes are disabled.');
        }
        if (! $this->connectionIsAllowed($connection)) {
            throw new InvalidConfiguration('The OAuth2 connection is not allowlisted for package routes.');
        }

        $applicationUrl = $this->config->get('app.url');
        if (! is_string($applicationUrl) || trim($applicationUrl) === '') {
            throw new InvalidConfiguration('A trusted application URL is required to derive the OAuth2 callback URL.');
        }
        $applicationUrl = $this->validatedTrustedApplicationUrl(
            $applicationUrl,
            'The trusted application URL for OAuth2 callback resolution is invalid.',
        );

        return rtrim($applicationUrl, '/').'/'.$this->routePrefix().'/'.rawurlencode($connection).'/callback';
    }

    public function usesPackageRoute(string $connection): bool
    {
        $values = $this->config->get("mautic.connections.{$connection}.oauth.redirect_uri");

        return (! is_string($values) || trim($values) === '')
            && $this->config->get('mautic.oauth.routes.enabled', false) === true
            && $this->connectionIsAllowed($connection);
    }

    public function routePrefix(): string
    {
        $prefix = $this->config->get('mautic.oauth.routes.prefix', 'mautic/oauth');
        if (! is_string($prefix) || trim($prefix, " /\t\n\r\0\x0B") === '') {
            throw new InvalidConfiguration('The package OAuth route prefix is invalid.');
        }

        $prefix = trim($prefix, '/');
        $segments = explode('/', $prefix);
        if (array_any($segments, static fn (string $segment): bool => $segment === '.'
            || $segment === '..'
            || preg_match('/^[A-Za-z0-9._~-]+$/D', $segment) !== 1)) {
            throw new InvalidConfiguration('The package OAuth route prefix is invalid.');
        }

        return $prefix;
    }

    public function connectionIsAllowed(string $connection): bool
    {
        $connections = $this->config->get('mautic.oauth.routes.connections', []);

        return is_array($connections)
            && array_all($connections, static fn (mixed $value): bool => is_string($value) && $value !== '')
            && in_array($connection, $connections, true);
    }

    private function validatedTrustedApplicationUrl(string $url, string $message): string
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidConfiguration($message);
        }

        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        if (! in_array($scheme, ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidConfiguration($message);
        }

        return rtrim($url, '/');
    }
}
