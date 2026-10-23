<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Tests\Support;

use RuntimeException;

final readonly class OAuthRestrictionWriteConfiguration
{
    public function __construct(public OAuthFixtureConfiguration $fixture) {}

    public static function enabledFromEnvironment(): bool
    {
        return getenv('MAUTIC_OAUTH_RESTRICTION_WRITE_TESTS') === '1';
    }

    public static function fromEnvironment(): self
    {
        $environment = [];
        foreach ([
            'MAUTIC_OAUTH_RESTRICTION_WRITE_TESTS',
            'MAUTIC_OAUTH_FIXTURE_TESTS',
            'MAUTIC_LIVE_DISPOSABLE',
        ] as $key) {
            $value = getenv($key);
            if (is_string($value)) {
                $environment[$key] = $value;
            }
        }

        return self::fromArray($environment, OAuthAcceptanceConfiguration::fromEnvironment(...));
    }

    /**
     * @param  array<string, string>  $environment
     * @param  callable(): OAuthAcceptanceConfiguration  $oauthConfiguration
     */
    public static function fromArray(array $environment, callable $oauthConfiguration): self
    {
        if (($environment['MAUTIC_OAUTH_RESTRICTION_WRITE_TESTS'] ?? null) !== '1') {
            throw new RuntimeException('Set MAUTIC_OAUTH_RESTRICTION_WRITE_TESTS=1 to enable restriction mutations.');
        }
        if (($environment['MAUTIC_OAUTH_FIXTURE_TESTS'] ?? null) !== '1') {
            throw new RuntimeException('Set MAUTIC_OAUTH_FIXTURE_TESTS=1 to enable OAuth fixture mutations.');
        }
        if (($environment['MAUTIC_LIVE_DISPOSABLE'] ?? null) !== OAuthFixtureConfiguration::DISPOSABLE_ACKNOWLEDGEMENT) {
            throw new RuntimeException('The exact MAUTIC_LIVE_DISPOSABLE acknowledgement is required.');
        }

        return new self(OAuthFixtureConfiguration::fromArray($environment, $oauthConfiguration()));
    }
}
