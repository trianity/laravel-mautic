<?php

declare(strict_types=1);

use Trianity\LaravelMautic\Tests\Support\OAuthFixtureConfiguration;
use Trianity\LaravelMautic\Tests\Support\OAuthStaticSegmentPreflight;

it('requires the dedicated static segment ID before constructing OAuth configuration', function () {
    $oauthFactoryCalled = false;

    expect(fn () => OAuthFixtureConfiguration::staticSegmentFromArray(
        [
            'MAUTIC_OAUTH_FIXTURE_TESTS' => '1',
            'MAUTIC_LIVE_DISPOSABLE' => OAuthFixtureConfiguration::DISPOSABLE_ACKNOWLEDGEMENT,
        ],
        function () use (&$oauthFactoryCalled): never {
            $oauthFactoryCalled = true;
            throw new LogicException('OAuth configuration must not be constructed.');
        },
    ))->toThrow(RuntimeException::class, 'MAUTIC_LIVE_OAUTH_STATIC_SEGMENT_ID is required')
        ->and($oauthFactoryCalled)->toBeFalse();
});

it('accepts only the exact dedicated segment with an explicitly empty filter list', function () {
    $preflight = new OAuthStaticSegmentPreflight;

    $definition = $preflight->validate([
        'list' => [
            'id' => 17,
            'name' => OAuthStaticSegmentPreflight::EXPECTED_NAME,
            'filters' => [],
            'isGlobal' => false,
        ],
    ], 17);

    expect($definition->remoteId)->toBe(17)
        ->and($definition->name)->toBe(OAuthStaticSegmentPreflight::EXPECTED_NAME);
});

it('rejects missing inaccessible non-dedicated and dynamically filtered segments', function (mixed $response) {
    expect(fn () => (new OAuthStaticSegmentPreflight)->validate($response, 17))
        ->toThrow(RuntimeException::class);
})->with([
    'missing response' => [['errors' => [['code' => 404]]]],
    'malformed response' => [['list' => ['id' => 17, 'name' => 'lm-live-mautic721-static']]],
    'wrong ID' => [['list' => ['id' => 18, 'name' => 'lm-live-mautic721-static', 'filters' => []]]],
    'business segment name' => [['list' => ['id' => 17, 'name' => 'Customers', 'filters' => []]]],
    'dynamic filters' => [['list' => [
        'id' => 17,
        'name' => 'lm-live-mautic721-static',
        'filters' => [['field' => 'email', 'operator' => 'notEmpty']],
    ]]],
]);
