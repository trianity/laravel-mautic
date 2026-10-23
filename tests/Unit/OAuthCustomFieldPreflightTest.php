<?php

declare(strict_types=1);

use Trianity\LaravelMautic\Tests\Support\OAuthCustomFieldPreflight;
use Trianity\LaravelMautic\Tests\Support\OAuthFixtureConfiguration;

it('requires the custom field alias before constructing OAuth configuration', function () {
    $oauthFactoryCalled = false;

    expect(fn () => OAuthFixtureConfiguration::customFieldFromArray(
        [
            'MAUTIC_OAUTH_FIXTURE_TESTS' => '1',
            'MAUTIC_LIVE_DISPOSABLE' => OAuthFixtureConfiguration::DISPOSABLE_ACKNOWLEDGEMENT,
        ],
        function () use (&$oauthFactoryCalled): never {
            $oauthFactoryCalled = true;
            throw new LogicException('OAuth configuration must not be constructed.');
        },
    ))->toThrow(RuntimeException::class, 'MAUTIC_LIVE_OAUTH_CUSTOM_FIELD_ALIAS is required')
        ->and($oauthFactoryCalled)->toBeFalse();
});

it('locates and validates the exact dedicated published contact text field', function () {
    $preflight = new OAuthCustomFieldPreflight;
    $id = $preflight->locate([
        'total' => 2,
        'fields' => [
            ['id' => 8, 'alias' => 'business_field'],
            ['id' => 17, 'alias' => 'lm_oauth_text'],
        ],
    ], 'lm_oauth_text');
    $definition = $preflight->validate(['field' => [
        'id' => 17,
        'alias' => 'lm_oauth_text',
        'label' => 'Dedicated acceptance field',
        'type' => 'text',
        'object' => 'lead',
        'isPublished' => true,
        'isRequired' => false,
        'isUniqueIdentifier' => false,
    ]], $id, 'lm_oauth_text');

    expect($definition->remoteId)->toBe(17)
        ->and($definition->alias)->toBe('lm_oauth_text');
});

it('rejects missing ambiguous and unsafe custom field metadata before mutation', function (string $phase, mixed $response) {
    $preflight = new OAuthCustomFieldPreflight;

    $call = $phase === 'locate'
        ? fn () => $preflight->locate($response, 'lm_oauth_text')
        : fn () => $preflight->validate($response, 17, 'lm_oauth_text');

    expect($call)->toThrow(RuntimeException::class);
})->with([
    'missing list' => ['locate', ['errors' => [['code' => 404]]]],
    'alias absent' => ['locate', ['fields' => [['id' => 8, 'alias' => 'business_field']]]],
    'alias ambiguous' => ['locate', ['fields' => [
        ['id' => 17, 'alias' => 'lm_oauth_text'],
        ['id' => 18, 'alias' => 'lm_oauth_text'],
    ]]],
    'blank label' => ['validate', ['field' => [
        'id' => 17, 'alias' => 'lm_oauth_text', 'label' => '', 'type' => 'text',
        'object' => 'lead', 'isPublished' => true, 'isRequired' => false, 'isUniqueIdentifier' => false,
    ]]],
    'wrong type' => ['validate', ['field' => [
        'id' => 17, 'alias' => 'lm_oauth_text', 'label' => 'Dedicated acceptance field',
        'type' => 'email', 'object' => 'lead', 'isPublished' => true, 'isRequired' => false,
        'isUniqueIdentifier' => false,
    ]]],
    'unpublished' => ['validate', ['field' => [
        'id' => 17, 'alias' => 'lm_oauth_text', 'label' => 'Dedicated acceptance field',
        'type' => 'text', 'object' => 'lead', 'isPublished' => false, 'isRequired' => false,
        'isUniqueIdentifier' => false,
    ]]],
]);
