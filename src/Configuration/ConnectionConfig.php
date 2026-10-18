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
        public string $username,
        #[SensitiveParameter] public string $password,
        public float $timeout,
        public float $connectTimeout,
    ) {}

    /** @param array<array-key, mixed> $values */
    public static function fromArray(string $name, #[SensitiveParameter] array $values): self
    {
        if (trim($name) === '') {
            throw new InvalidConfiguration('A Mautic connection name is required.');
        }

        if (($values['auth'] ?? 'basic') !== 'basic') {
            throw new InvalidConfiguration('This foundation supports Basic Auth only. OAuth2 is planned.');
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

        $username = $values['username'] ?? null;
        $password = $values['password'] ?? null;

        if (! is_string($username) || trim($username) === '' || ! is_string($password) || trim($password) === '') {
            throw new InvalidConfiguration('Mautic Basic Auth requires a username and a password.');
        }

        $timeout = self::timeout($values['timeout'] ?? 10);
        $connectTimeout = self::timeout($values['connect_timeout'] ?? 3);

        if ($connectTimeout > $timeout) {
            throw new InvalidConfiguration('Mautic connect_timeout cannot exceed timeout.');
        }

        return new self($name, rtrim($url, '/'), $username, $password, $timeout, $connectTimeout);
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
