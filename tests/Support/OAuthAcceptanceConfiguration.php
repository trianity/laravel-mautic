<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Tests\Support;

use RuntimeException;

final readonly class OAuthAcceptanceConfiguration
{
    public const CALLBACK_URI = 'http://127.0.0.1:8765/oauth/callback';

    /**
     * @param  list<string>  $resolvedAddresses
     */
    private function __construct(
        public string $origin,
        public string $installPath,
        public string $serverVersion,
        public string $caBundle,
        public string $clientId,
        public string $redirectUri,
        public array $resolvedAddresses,
        public ?int $fixtureContactId,
        public ?string $fixtureEmail,
    ) {}

    public static function fromEnvironment(): self
    {
        $keys = [
            'MAUTIC_OAUTH_ACCEPTANCE_TESTS',
            'MAUTIC_LIVE_URL',
            'MAUTIC_LIVE_INSTALL_PATH',
            'MAUTIC_LIVE_SERVER_VERSION',
            'MAUTIC_LIVE_CA_BUNDLE',
            'MAUTIC_LIVE_OAUTH_CLIENT_ID',
            'MAUTIC_LIVE_OAUTH_CLIENT_SECRET',
            'MAUTIC_LIVE_OAUTH_REDIRECT_URI',
            'MAUTIC_LIVE_OAUTH_FIXTURE_CONTACT_ID',
            'MAUTIC_LIVE_OAUTH_FIXTURE_EMAIL',
        ];
        $environment = [];
        foreach ($keys as $key) {
            $value = getenv($key);
            if (is_string($value)) {
                $environment[$key] = $value;
            }
        }

        return self::fromArray($environment, self::resolve(...));
    }

    /**
     * @param  array<string, string>  $environment
     * @param  callable(string): list<string>  $resolver
     */
    public static function fromArray(array $environment, callable $resolver): self
    {
        if (($environment['MAUTIC_OAUTH_ACCEPTANCE_TESTS'] ?? null) !== '1') {
            throw new RuntimeException('Set MAUTIC_OAUTH_ACCEPTANCE_TESTS=1 to enable OAuth acceptance checks.');
        }

        $origin = rtrim(self::required($environment, 'MAUTIC_LIVE_URL'), '/');
        if (! hash_equals('https://mautic.test', $origin)) {
            throw new RuntimeException('OAuth acceptance permits only the exact https://mautic.test origin.');
        }

        $addresses = array_values(array_unique($resolver('mautic.test')));
        if ($addresses === [] || array_any($addresses, static fn (string $address): bool => ! self::isLoopback($address))) {
            throw new RuntimeException('mautic.test must resolve exclusively to loopback addresses.');
        }

        $installPath = rtrim(self::required($environment, 'MAUTIC_LIVE_INSTALL_PATH'), DIRECTORY_SEPARATOR);
        $serverVersion = self::required($environment, 'MAUTIC_LIVE_SERVER_VERSION');
        $installedVersion = self::installedMauticVersion($installPath);
        if (! hash_equals($serverVersion, $installedVersion) || ! hash_equals('7.2.1', $installedVersion)) {
            throw new RuntimeException('OAuth acceptance requires the installed Mautic core-lib version 7.2.1.');
        }

        $caBundle = self::required($environment, 'MAUTIC_LIVE_CA_BUNDLE');
        if (! is_file($caBundle) || ! is_readable($caBundle)) {
            throw new RuntimeException('MAUTIC_LIVE_CA_BUNDLE must be a readable trust anchor.');
        }

        $clientId = self::required($environment, 'MAUTIC_LIVE_OAUTH_CLIENT_ID');
        self::required($environment, 'MAUTIC_LIVE_OAUTH_CLIENT_SECRET');
        $redirectUri = self::required($environment, 'MAUTIC_LIVE_OAUTH_REDIRECT_URI');
        if (! hash_equals(self::CALLBACK_URI, $redirectUri)) {
            throw new RuntimeException('OAuth acceptance requires the exact dedicated loopback callback URI.');
        }

        $fixtureContactId = self::positiveInteger($environment['MAUTIC_LIVE_OAUTH_FIXTURE_CONTACT_ID'] ?? null);
        $fixtureEmail = $environment['MAUTIC_LIVE_OAUTH_FIXTURE_EMAIL'] ?? null;
        if ($fixtureEmail !== null && trim($fixtureEmail) === '') {
            $fixtureEmail = null;
        }

        return new self(
            $origin,
            $installPath,
            $serverVersion,
            $caBundle,
            $clientId,
            $redirectUri,
            $addresses,
            $fixtureContactId,
            $fixtureEmail,
        );
    }

    /** @param array<string, string> $environment */
    private static function required(array $environment, string $key): string
    {
        $value = $environment[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("OAuth acceptance requires {$key}.");
        }

        return $value;
    }

    private static function installedMauticVersion(string $installPath): string
    {
        $path = $installPath.'/vendor/composer/installed.json';
        if (! is_file($path)) {
            throw new RuntimeException('The configured Mautic installation metadata is unavailable.');
        }

        $installed = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $packages = is_array($installed) && is_array($installed['packages'] ?? null)
            ? $installed['packages']
            : $installed;
        if (! is_array($packages)) {
            throw new RuntimeException('The configured Mautic installation metadata is invalid.');
        }

        foreach ($packages as $package) {
            if (is_array($package) && ($package['name'] ?? null) === 'mautic/core-lib'
                && is_string($package['version'] ?? null)) {
                return $package['version'];
            }
        }

        throw new RuntimeException('The installed Mautic core-lib version could not be determined.');
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        $addresses = gethostbynamel($host);
        $resolved = is_array($addresses) ? $addresses : [];
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                $address = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($address)) {
                    $resolved[] = $address;
                }
            }
        }

        return array_values(array_unique($resolved));
    }

    private static function isLoopback(string $address): bool
    {
        if ($address === '::1') {
            return true;
        }

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && str_starts_with($address, '127.');
    }

    private static function positiveInteger(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! is_int($validated)) {
            throw new RuntimeException('MAUTIC_LIVE_OAUTH_FIXTURE_CONTACT_ID must be a positive integer.');
        }

        return $validated;
    }
}
