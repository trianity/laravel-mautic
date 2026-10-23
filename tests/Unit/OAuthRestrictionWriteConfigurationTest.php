<?php

declare(strict_types=1);

use Trianity\LaravelMautic\Tests\Support\OAuthAcceptanceConfiguration;
use Trianity\LaravelMautic\Tests\Support\OAuthFixtureConfiguration;
use Trianity\LaravelMautic\Tests\Support\OAuthRestrictionWriteConfiguration;
use Trianity\LaravelMautic\Tests\Support\OAuthRestrictionWriteEvidence;

it('validates every restriction-write mutation gate before OAuth construction', function (array $changes) {
    $environment = [
        'MAUTIC_OAUTH_RESTRICTION_WRITE_TESTS' => '1',
        'MAUTIC_OAUTH_FIXTURE_TESTS' => '1',
        'MAUTIC_LIVE_DISPOSABLE' => OAuthFixtureConfiguration::DISPOSABLE_ACKNOWLEDGEMENT,
    ];
    foreach ($changes as $key => $value) {
        if ($value === null) {
            unset($environment[$key]);
        } else {
            $environment[$key] = $value;
        }
    }
    $oauthConstructed = false;

    expect(fn () => OAuthRestrictionWriteConfiguration::fromArray(
        $environment,
        function () use (&$oauthConstructed): never {
            $oauthConstructed = true;
            throw new LogicException('OAuth configuration must not be constructed.');
        },
    ))->toThrow(RuntimeException::class)
        ->and($oauthConstructed)->toBeFalse();
})->with([
    'dedicated opt-in missing' => [['MAUTIC_OAUTH_RESTRICTION_WRITE_TESTS' => null]],
    'fixture opt-in missing' => [['MAUTIC_OAUTH_FIXTURE_TESTS' => null]],
    'acknowledgement missing' => [['MAUTIC_LIVE_DISPOSABLE' => null]],
    'acknowledgement invalid' => [['MAUTIC_LIVE_DISPOSABLE' => 'yes']],
]);

it('accepts complete restriction-write gates before constructing OAuth configuration', function () {
    $reflection = new ReflectionClass(OAuthAcceptanceConfiguration::class);
    $oauth = $reflection->newInstanceWithoutConstructor();
    $configuration = OAuthRestrictionWriteConfiguration::fromArray([
        'MAUTIC_OAUTH_RESTRICTION_WRITE_TESTS' => '1',
        'MAUTIC_OAUTH_FIXTURE_TESTS' => '1',
        'MAUTIC_LIVE_DISPOSABLE' => OAuthFixtureConfiguration::DISPOSABLE_ACKNOWLEDGEMENT,
    ], fn (): OAuthAcceptanceConfiguration => $oauth);

    expect($configuration->fixture->oauth)->toBe($oauth);
});

it('stores only sanitized restriction-write scenario evidence', function () {
    $directory = sys_get_temp_dir().'/mautic-restriction-write-'.bin2hex(random_bytes(6));

    try {
        $path = (new OAuthRestrictionWriteEvidence)->write([
            'clean' => [
                'outcome' => 'released',
                'operation_requests' => 10,
                'cleanup_requests' => 3,
                'cleanup' => 'deleted',
            ],
        ], $directory);
        $payload = (string) file_get_contents($path);

        expect(fileperms($path) & 0777)->toBe(0600)
            ->and($payload)->toContain('"status": "passed"', '"outcome": "released"')
            ->not->toContain('@', 'comments', 'remote_id');
    } finally {
        if (isset($path) && is_file($path)) {
            unlink($path);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});

it('rejects an invalid restriction-write evidence status', function () {
    expect(fn () => (new OAuthRestrictionWriteEvidence)->write([], status: 'unknown'))
        ->toThrow(RuntimeException::class);
});
