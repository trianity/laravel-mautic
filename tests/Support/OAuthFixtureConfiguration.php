<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Tests\Support;

use RuntimeException;

final readonly class OAuthFixtureConfiguration
{
    public const DISPOSABLE_ACKNOWLEDGEMENT = 'I_UNDERSTAND_THIS_DELETES_OWN_FIXTURES';

    public function __construct(
        public OAuthAcceptanceConfiguration $oauth,
        public ?int $staticSegmentId,
    ) {}

    public static function fromEnvironment(): self
    {
        $environment = [];
        foreach ([
            'MAUTIC_OAUTH_FIXTURE_TESTS',
            'MAUTIC_LIVE_DISPOSABLE',
            'MAUTIC_LIVE_OAUTH_STATIC_SEGMENT_ID',
        ] as $key) {
            $value = getenv($key);
            if (is_string($value)) {
                $environment[$key] = $value;
            }
        }

        return self::validated($environment, OAuthAcceptanceConfiguration::fromEnvironment());
    }

    /** @param array<string, string> $environment */
    public static function fromArray(
        array $environment,
        OAuthAcceptanceConfiguration $oauth,
    ): self {
        return self::validated($environment, $oauth);
    }

    public static function staticSegmentFromEnvironment(): self
    {
        $environment = [];
        foreach ([
            'MAUTIC_OAUTH_FIXTURE_TESTS',
            'MAUTIC_LIVE_DISPOSABLE',
            'MAUTIC_LIVE_OAUTH_STATIC_SEGMENT_ID',
        ] as $key) {
            $value = getenv($key);
            if (is_string($value)) {
                $environment[$key] = $value;
            }
        }

        return self::staticSegmentFromArray(
            $environment,
            OAuthAcceptanceConfiguration::fromEnvironment(...),
        );
    }

    /**
     * @param  array<string, string>  $environment
     * @param  callable(): OAuthAcceptanceConfiguration  $oauthConfiguration
     */
    public static function staticSegmentFromArray(array $environment, callable $oauthConfiguration): self
    {
        self::validateMutationGates($environment);
        $segmentId = self::segmentId($environment);
        if ($segmentId === null) {
            throw new RuntimeException('MAUTIC_LIVE_OAUTH_STATIC_SEGMENT_ID is required for static segment acceptance.');
        }

        return new self($oauthConfiguration(), $segmentId);
    }

    /** @param array<string, string> $environment */
    private static function validated(array $environment, OAuthAcceptanceConfiguration $oauth): self
    {
        self::validateMutationGates($environment);

        return new self($oauth, self::segmentId($environment));
    }

    /** @param array<string, string> $environment */
    private static function segmentId(array $environment): ?int
    {
        $segment = $environment['MAUTIC_LIVE_OAUTH_STATIC_SEGMENT_ID'] ?? null;
        $segmentId = null;
        if (is_string($segment) && $segment !== '') {
            $validated = filter_var($segment, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (! is_int($validated)) {
                throw new RuntimeException('MAUTIC_LIVE_OAUTH_STATIC_SEGMENT_ID must be a positive integer.');
            }
            $segmentId = $validated;
        }

        return $segmentId;
    }

    /** @param array<string, string> $environment */
    private static function validateMutationGates(array $environment): void
    {
        if (($environment['MAUTIC_OAUTH_FIXTURE_TESTS'] ?? null) !== '1') {
            throw new RuntimeException('Set MAUTIC_OAUTH_FIXTURE_TESTS=1 to enable OAuth fixture mutations.');
        }
        if (($environment['MAUTIC_LIVE_DISPOSABLE'] ?? null) !== self::DISPOSABLE_ACKNOWLEDGEMENT) {
            throw new RuntimeException('The exact MAUTIC_LIVE_DISPOSABLE acknowledgement is required.');
        }
    }
}
