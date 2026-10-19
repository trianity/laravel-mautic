<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Configuration;

use SensitiveParameter;
use Trianity\LaravelMautic\Exceptions\InvalidConfiguration;

final readonly class ConnectionConfig
{
    private function __construct(
        public string $name,
        public string $url,
        public string $auth,
        public string $username,
        #[SensitiveParameter] public string $password,
        public string $oauthRedirectUri,
        public float $timeout,
        public float $connectTimeout,
    ) {}

    /** @param array<array-key, mixed> $values */
    public static function fromArray(string $name, #[SensitiveParameter] array $values): self
    {
        if (trim($name) === '') {
            throw new InvalidConfiguration('A Mautic connection name is required.');
        }

        $auth = $values['auth'] ?? 'basic';
        if (! is_string($auth) || ! in_array($auth, ['basic', 'oauth2'], true)) {
            throw new InvalidConfiguration('Mautic auth must be basic or oauth2.');
        }

        $url = $values['url'] ?? null;

        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidConfiguration('A valid Mautic instance URL is required.');
        }

        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';

        if ($scheme !== 'https' && ! ($scheme === 'http' && ($values['allow_http'] ?? false) === true)) {
            throw new InvalidConfiguration('Mautic requires HTTPS. Local HTTP must be explicitly enabled.');
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidConfiguration('Mautic URLs cannot contain credentials, a query or a fragment.');
        }

        $username = '';
        $password = '';
        $oauthRedirectUri = '';
        if ($auth === 'basic') {
            $username = $values['username'] ?? null;
            $password = $values['password'] ?? null;

            if (! is_string($username) || trim($username) === '' || ! is_string($password) || trim($password) === '') {
                throw new InvalidConfiguration('Mautic Basic Auth requires a username and a password.');
            }
        } else {
            $oauth = $values['oauth'] ?? null;
            $clientId = is_array($oauth) ? ($oauth['client_id'] ?? null) : null;
            $secretEnvironment = is_array($oauth) ? ($oauth['client_secret_env'] ?? null) : null;
            $oauthRedirectUri = is_array($oauth) ? ($oauth['redirect_uri'] ?? null) : null;

            if (! is_string($clientId) || trim($clientId) === ''
                || ! is_string($secretEnvironment) || trim($secretEnvironment) === ''
                || ! is_string($oauthRedirectUri) || filter_var($oauthRedirectUri, FILTER_VALIDATE_URL) === false) {
                throw new InvalidConfiguration('Mautic OAuth2 requires a client ID, secret environment variable and redirect URI.');
            }
        }

        $timeout = self::timeout($values['timeout'] ?? 10);
        $connectTimeout = self::timeout($values['connect_timeout'] ?? 3);

        if ($connectTimeout > $timeout) {
            throw new InvalidConfiguration('Mautic connect_timeout cannot exceed timeout.');
        }

        return new self(
            $name,
            rtrim($url, '/'),
            $auth,
            $username,
            $password,
            $oauthRedirectUri,
            $timeout,
            $connectTimeout,
        );
    }

    public function apiUrl(): string
    {
        return str_ends_with($this->url, '/api') ? $this->url.'/' : $this->url.'/api/';
    }

    private static function timeout(mixed $value): float
    {
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value <= 0) {
            throw new InvalidConfiguration('Mautic timeouts must be finite positive numbers.');
        }

        return (float) $value;
    }
}
