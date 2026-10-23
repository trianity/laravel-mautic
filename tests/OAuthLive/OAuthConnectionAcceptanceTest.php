<?php

declare(strict_types=1);

use Trianity\LaravelMautic\Data\ValuePresence;
use Trianity\LaravelMautic\Read\ContactSearch;
use Trianity\LaravelMautic\Tests\Support\OAuthAcceptanceConfiguration;
use Trianity\LaravelMautic\Tests\Support\OAuthAcceptanceHarness;

function oauthAcceptanceHarness(): OAuthAcceptanceHarness
{
    if (getenv('MAUTIC_OAUTH_ACCEPTANCE_TESTS') !== '1') {
        test()->markTestSkipped('Set MAUTIC_OAUTH_ACCEPTANCE_TESTS=1 for the isolated OAuth read-only suite.');
    }

    return new OAuthAcceptanceHarness(OAuthAcceptanceConfiguration::fromEnvironment());
}

it('persists refreshes and uses OAuth for bounded API reads without Basic Auth', function () {
    $harness = oauthAcceptanceHarness();
    $initial = $harness->requiredTokenRecord();
    $connection = $harness->connection();
    $page = $connection->contactReads()->search(new ContactSearch('', 0, 1));

    expect($initial->generation)->toBeGreaterThanOrEqual(1)
        ->and($initial->token->expiresAt)->toBeGreaterThan(0)
        ->and($page->limit)->toBe(1)
        ->and(count($page->contacts))->toBeLessThanOrEqual(1)
        ->and($page->total)->toBeGreaterThanOrEqual(count($page->contacts));

    $invalid = $harness->invalidBearerProbe();
    expect($invalid->getStatusCode())->toBeIn([401, 403])
        ->and($invalid->hasHeader('Location'))->toBeFalse();

    $refresh = $harness->refreshExpiredDedicatedToken();
    expect($refresh->after->generation)->toBe($refresh->before->generation + 2)
        ->and($refresh->after->token->expiresAt)->toBeGreaterThan(time());

    $postRefreshPage = $harness->connection()->contactReads()->search(new ContactSearch('', 0, 1));
    expect($postRefreshPage->limit)->toBe(1)
        ->and(count($postRefreshPage->contacts))->toBeLessThanOrEqual(1);

    $v2 = $harness->apiV2Probe($refresh->after->token);
    expect($v2->getStatusCode())->toBeIn([200, 401, 403])
        ->and($v2->hasHeader('Location'))->toBeFalse();
    $mediaType = strtolower(trim(explode(';', $v2->getHeaderLine('Content-Type'))[0]));
    expect($mediaType)->toBeIn([
        'application/json',
        'application/problem+json',
        'application/ld+json',
        'application/vnd.api+json',
    ]);
    $payload = json_decode((string) $v2->getBody(), true, flags: JSON_THROW_ON_ERROR);
    expect($payload)->toBeArray();
});

it('performs fixture-specific ID email segment and DNC reads only when dedicated evidence exists', function () {
    $harness = oauthAcceptanceHarness();
    $configuration = $harness->environment;
    if ($configuration->fixtureContactId === null || $configuration->fixtureEmail === null) {
        test()->markTestSkipped('No dedicated existing OAuth read fixture is configured.');
    }

    $connection = $harness->connection();
    $contact = $connection->contactReads()->find($configuration->fixtureContactId);
    $email = $contact->field('email');
    expect($email->presence)->toBe(ValuePresence::Value)
        ->and(is_string($email->value()))->toBeTrue()
        ->and(hash_equals($configuration->fixtureEmail, (string) $email->value()))->toBeTrue();

    $discovery = $connection->contactDiscovery()->exactEmail($configuration->fixtureEmail);
    expect($discovery->uniqueContact()?->remoteId)->toBe($configuration->fixtureContactId);

    $memberships = $connection->contactReads()->segments($configuration->fixtureContactId);
    expect($memberships->contactId)->toBe($configuration->fixtureContactId)
        ->and($memberships->memberships)->toBeArray()
        ->and($contact->doNotContact->presence)->toBeInstanceOf(ValuePresence::class);
});
