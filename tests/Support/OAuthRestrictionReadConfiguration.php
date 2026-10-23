<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Tests\Support;

use RuntimeException;

final readonly class OAuthRestrictionReadConfiguration
{
    public function __construct(
        public OAuthAcceptanceConfiguration $oauth,
        public int $contactId,
        public string $contactEmail,
        public int $dncId,
        public string $comments,
    ) {}

    public static function enabledFromEnvironment(): bool
    {
        return getenv('MAUTIC_OAUTH_RESTRICTION_READ_TESTS') === '1';
    }

    /** @param array<string, string> $environment */
    public static function enabled(array $environment): bool
    {
        return ($environment['MAUTIC_OAUTH_RESTRICTION_READ_TESTS'] ?? null) === '1';
    }

    public static function fromEnvironment(): self
    {
        $environment = [];
        foreach ([
            'MAUTIC_OAUTH_RESTRICTION_READ_TESTS',
            'MAUTIC_LIVE_OAUTH_RESTRICTION_CONTACT_ID',
            'MAUTIC_LIVE_OAUTH_RESTRICTION_CONTACT_EMAIL',
            'MAUTIC_LIVE_OAUTH_RESTRICTION_DNC_ID',
            'MAUTIC_LIVE_OAUTH_RESTRICTION_COMMENTS',
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
        if (! self::enabled($environment)) {
            throw new RuntimeException('Set MAUTIC_OAUTH_RESTRICTION_READ_TESTS=1 to enable the read-only restriction check.');
        }

        $contactId = self::positiveInteger($environment, 'MAUTIC_LIVE_OAUTH_RESTRICTION_CONTACT_ID');
        $dncId = self::positiveInteger($environment, 'MAUTIC_LIVE_OAUTH_RESTRICTION_DNC_ID');
        $email = self::required($environment, 'MAUTIC_LIVE_OAUTH_RESTRICTION_CONTACT_EMAIL');
        if (trim($email) !== $email || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('MAUTIC_LIVE_OAUTH_RESTRICTION_CONTACT_EMAIL must be one valid exact email address.');
        }
        $comments = self::required($environment, 'MAUTIC_LIVE_OAUTH_RESTRICTION_COMMENTS');

        return new self($oauthConfiguration(), $contactId, $email, $dncId, $comments);
    }

    /** @param array<string, string> $environment */
    private static function required(array $environment, string $key): string
    {
        $value = $environment[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("{$key} is required for read-only restriction acceptance.");
        }

        return $value;
    }

    /** @param array<string, string> $environment */
    private static function positiveInteger(array $environment, string $key): int
    {
        $value = self::required($environment, $key);
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! is_int($validated)) {
            throw new RuntimeException("{$key} must be a positive integer.");
        }

        return $validated;
    }
}
