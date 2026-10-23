<?php

declare(strict_types=1);

use Trianity\LaravelMautic\Data\Contact;
use Trianity\LaravelMautic\Data\ValuePresence;
use Trianity\LaravelMautic\Exceptions\AmbiguousMutationOutcome;
use Trianity\LaravelMautic\Exceptions\ContactNotFound;
use Trianity\LaravelMautic\Identity\ContactDiscoveryOutcome;
use Trianity\LaravelMautic\MauticConnection;
use Trianity\LaravelMautic\Tests\Support\OAuthAcceptanceHarness;
use Trianity\LaravelMautic\Tests\Support\OAuthAcceptanceRequestBudget;
use Trianity\LaravelMautic\Tests\Support\OAuthCustomFieldPreflight;
use Trianity\LaravelMautic\Tests\Support\OAuthFixtureConfiguration;
use Trianity\LaravelMautic\Tests\Support\OAuthFixtureEvidence;
use Trianity\LaravelMautic\Write\ContactFields;

function oauthCustomFieldValue(Contact $contact, string $alias): mixed
{
    $field = $contact->field($alias);
    expect($field->presence)->toBe(ValuePresence::Value, "Expected {$alias} to have a value.");

    return $field->value();
}

/** @return list<int> */
function oauthCustomFieldMembershipIds(MauticConnection $connection, int $remoteId): array
{
    $ids = array_column($connection->contactReads()->segments($remoteId)->memberships, 'remoteId');
    sort($ids);

    return $ids;
}

function deleteOwnedOAuthCustomFieldFixture(
    MauticConnection $connection,
    int $remoteId,
    string $email,
    OAuthFixtureEvidence $evidence,
): void {
    $stored = $connection->contactReads()->find($remoteId);
    $storedEmail = $stored->field('email');
    if ($stored->remoteId !== $remoteId || $storedEmail->presence !== ValuePresence::Value
        || ! is_string($storedEmail->value()) || ! hash_equals($email, $storedEmail->value())) {
        throw new RuntimeException('Refusing to delete a custom-field fixture without exact current ownership evidence.');
    }

    $response = $connection->contacts()->delete($remoteId);
    if (! is_array($response) || array_key_exists('errors', $response)) {
        throw new RuntimeException('The owned OAuth custom-field fixture could not be deleted safely.');
    }

    expect(fn () => $connection->contactReads()->find($remoteId))->toThrow(ContactNotFound::class);
    $evidence->deleted();
}

it('verifies a custom field lifecycle through the OAuth fixture', function () {
    if (getenv('MAUTIC_OAUTH_FIXTURE_TESTS') !== '1') {
        test()->markTestSkipped('Set MAUTIC_OAUTH_FIXTURE_TESTS=1 for OAuth custom field acceptance.');
    }

    $configuration = OAuthFixtureConfiguration::customFieldFromEnvironment();
    $alias = $configuration->customFieldAlias;
    if ($alias === null) {
        throw new RuntimeException('The required custom-field alias was not retained.');
    }

    $requestBudget = new OAuthAcceptanceRequestBudget;
    $harness = new OAuthAcceptanceHarness($configuration->oauth, requestBudget: $requestBudget);
    $connection = $harness->connection();
    $fieldsApi = $connection->api('contactFields');
    $preflight = new OAuthCustomFieldPreflight;
    $fieldId = $preflight->locate($fieldsApi->getList('', 0, 1000), $alias);
    $preflight->validate($fieldsApi->get($fieldId), $fieldId, $alias);

    $runId = bin2hex(random_bytes(12));
    $email = 'lm-live-mautic721.custom.'.$runId.'@example.test';
    $initialValue = 'initial-'.$runId;
    $updatedValue = 'updated-'.$runId;
    $evidence = new OAuthFixtureEvidence($runId, $email);
    $remoteId = null;
    $failure = null;

    try {
        expect($connection->contactDiscovery()->exactEmail($email)->outcome)
            ->toBe(ContactDiscoveryOutcome::None);

        try {
            $created = $connection->contactWrites()->create(ContactFields::fromArray([
                'email' => $email,
                'firstname' => 'OAuthCustomBefore',
                'lastname' => 'FieldFixture',
                $alias => $initialValue,
            ], ['email', 'firstname', 'lastname', $alias]));
        } catch (AmbiguousMutationOutcome $exception) {
            $evidence->ambiguousCreate();
            throw $exception;
        }
        $remoteId = $created->remoteId;
        $evidence->created($remoteId);

        $initial = $connection->contactReads()->find($remoteId);
        expect(oauthCustomFieldValue($initial, $alias))->toBe($initialValue)
            ->and($initial->doNotContact->presence)->toBe(ValuePresence::Value);
        $initialDnc = $initial->doNotContact->value();
        $initialMemberships = oauthCustomFieldMembershipIds($connection, $remoteId);

        $connection->contactWrites()->update($remoteId, ContactFields::fromArray(
            [$alias => $updatedValue],
            [$alias],
        ));
        expect(oauthCustomFieldValue($connection->contactReads()->find($remoteId), $alias))->toBe($updatedValue);

        $connection->contactWrites()->update($remoteId, ContactFields::fromArray(
            ['firstname' => 'OAuthCustomAfter'],
            ['firstname'],
        ));
        $unrelated = $connection->contactReads()->find($remoteId);
        expect(oauthCustomFieldValue($unrelated, $alias))->toBe($updatedValue)
            ->and(oauthCustomFieldValue($unrelated, 'firstname'))->toBe('OAuthCustomAfter');

        $connection->contactWrites()->update($remoteId, ContactFields::fromArray(
            [$alias => ''],
            [$alias],
        ));
        $cleared = $connection->contactReads()->find($remoteId);
        $clearedField = $cleared->field($alias);
        $isCleared = $clearedField->presence === ValuePresence::Null
            || ($clearedField->presence === ValuePresence::Value && $clearedField->value() === '');
        expect($isCleared)->toBeTrue()
            ->and($cleared->doNotContact->presence)->toBe(ValuePresence::Value)
            ->and($cleared->doNotContact->value())->toEqual($initialDnc)
            ->and(oauthCustomFieldMembershipIds($connection, $remoteId))->toEqual($initialMemberships)
            ->and($requestBudget->operationAttempts())->toBeLessThanOrEqual($requestBudget->operationLimit());
    } catch (Throwable $exception) {
        $failure = $requestBudget->operationWasRejected()
            ? new RuntimeException('OAuth acceptance operation request budget exhausted.')
            : $exception;
    }

    $requestBudget->beginCleanup();
    $cleanupFailure = null;
    if (is_int($remoteId)) {
        try {
            deleteOwnedOAuthCustomFieldFixture($connection, $remoteId, $email, $evidence);
        } catch (Throwable $exception) {
            $evidence->cleanupFailed();
            $cleanupFailure = $requestBudget->cleanupWasRejected()
                ? new RuntimeException('OAuth acceptance cleanup request budget exhausted.')
                : $exception;
        }
    }
    $evidence->requestCounts($requestBudget->operationAttempts(), $requestBudget->cleanupAttempts());

    if ($failure !== null) {
        throw $failure;
    }
    if ($cleanupFailure !== null) {
        throw $cleanupFailure;
    }
});
