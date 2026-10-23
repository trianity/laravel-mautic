<?php

declare(strict_types=1);

use Trianity\LaravelMautic\Data\ValuePresence;
use Trianity\LaravelMautic\Tests\Support\OAuthAcceptanceHarness;
use Trianity\LaravelMautic\Tests\Support\OAuthAcceptanceRequestBudget;
use Trianity\LaravelMautic\Tests\Support\OAuthRestrictionReadConfiguration;
use Trianity\LaravelMautic\Tests\Support\OAuthRestrictionReadEvidence;
use Trianity\LaravelMautic\Tests\Support\OAuthRestrictionReadVerifier;

it('verifies an existing manual restriction through two read-only observations', function () {
    if (! OAuthRestrictionReadConfiguration::enabledFromEnvironment()) {
        test()->markTestSkipped('Set MAUTIC_OAUTH_RESTRICTION_READ_TESTS=1 for read-only restriction acceptance.');
    }

    $configuration = OAuthRestrictionReadConfiguration::fromEnvironment();
    $requestBudget = new OAuthAcceptanceRequestBudget(operationLimit: 4, cleanupLimit: 0);
    $harness = new OAuthAcceptanceHarness($configuration->oauth, requestBudget: $requestBudget);
    $generationBefore = $harness->requiredTokenRecord()->generation;
    $reader = $harness->connection()->contactReads();

    $failure = null;
    try {
        $first = $reader->find($configuration->contactId);
        $second = $reader->find($configuration->contactId);
        (new OAuthRestrictionReadVerifier)->verify(
            $first,
            $second,
            $configuration,
            expectedDateAddedPresence: ValuePresence::Missing,
        );
    } catch (Throwable $exception) {
        $failure = $exception;
    }

    $generationAfter = $harness->requiredTokenRecord()->generation;
    $tokenRefreshed = $generationAfter > $generationBefore;
    $evidencePath = (new OAuthRestrictionReadEvidence)->write(
        $failure === null ? 'passed' : 'failed',
        $requestBudget->operationAttempts(),
        $tokenRefreshed,
    );

    if ($failure !== null) {
        throw $failure;
    }

    expect($requestBudget->operationAttempts())->toBeGreaterThanOrEqual(2)
        ->toBeLessThanOrEqual($requestBudget->operationLimit())
        ->and($requestBudget->cleanupAttempts())->toBe(0)
        ->and(is_file($evidencePath))->toBeTrue();
});
