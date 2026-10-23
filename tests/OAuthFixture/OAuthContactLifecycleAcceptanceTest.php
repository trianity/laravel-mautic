<?php

declare(strict_types=1);

use Trianity\LaravelMautic\Data\Contact;
use Trianity\LaravelMautic\Data\ValuePresence;
use Trianity\LaravelMautic\Exceptions\AmbiguousMutationOutcome;
use Trianity\LaravelMautic\Exceptions\ContactAlreadyExists;
use Trianity\LaravelMautic\Exceptions\ContactNotFound;
use Trianity\LaravelMautic\Identity\ContactDiscoveryOutcome;
use Trianity\LaravelMautic\MauticConnection;
use Trianity\LaravelMautic\Tests\Support\OAuthAcceptanceHarness;
use Trianity\LaravelMautic\Tests\Support\OAuthFixtureConfiguration;
use Trianity\LaravelMautic\Tests\Support\OAuthFixtureEvidence;
use Trianity\LaravelMautic\Write\ContactFields;

function oauthFixtureField(Contact $contact, string $alias): mixed
{
    $field = $contact->field($alias);
    expect($field->presence)->toBe(ValuePresence::Value, "Expected {$alias} to have a value.");

    return $field->value();
}

function oauthFixtureMembershipIds(MauticConnection $connection, int $remoteId): array
{
    $ids = array_column($connection->contactReads()->segments($remoteId)->memberships, 'remoteId');
    sort($ids);

    return $ids;
}

function deleteOwnedOAuthFixture(
    MauticConnection $connection,
    int $remoteId,
    string $email,
    OAuthFixtureEvidence $evidence,
): void {
    $stored = $connection->contactReads()->find($remoteId);
    $storedEmail = $stored->field('email');
    if ($stored->remoteId !== $remoteId || $storedEmail->presence !== ValuePresence::Value
        || ! is_string($storedEmail->value()) || ! hash_equals($email, $storedEmail->value())) {
        throw new RuntimeException('Refusing to delete a contact without exact current ownership evidence.');
    }

    $response = $connection->contacts()->delete($remoteId);
    if (! is_array($response) || array_key_exists('errors', $response)) {
        throw new RuntimeException('The owned OAuth fixture could not be deleted safely.');
    }

    expect(fn () => $connection->contactReads()->find($remoteId))->toThrow(ContactNotFound::class);
    $evidence->deleted();
}

it('verifies an owned OAuth contact lifecycle and cleans up the exact fixture', function () {
    if (getenv('MAUTIC_OAUTH_FIXTURE_TESTS') !== '1') {
        test()->markTestSkipped('Set MAUTIC_OAUTH_FIXTURE_TESTS=1 for the isolated OAuth fixture suite.');
    }

    $configuration = OAuthFixtureConfiguration::fromEnvironment();
    $harness = new OAuthAcceptanceHarness($configuration->oauth);
    $connection = $harness->connection();
    $runId = bin2hex(random_bytes(12));
    $email = 'lm-live-mautic721.'.$runId.'@example.test';
    $evidence = new OAuthFixtureEvidence($runId, $email);
    $remoteId = null;
    $segmentAdded = false;
    $failure = null;

    try {
        $initial = $connection->contactDiscovery()->exactEmail($email);
        expect($initial->outcome)->toBe(ContactDiscoveryOutcome::None);

        try {
            $created = $connection->contactWrites()->create(ContactFields::fromArray([
                'email' => $email,
                'firstname' => 'OAuthBefore',
                'lastname' => 'LifecycleFixture',
                'phone' => '+36 30 555 0101',
            ], ['email', 'firstname', 'lastname', 'phone']));
        } catch (AmbiguousMutationOutcome $exception) {
            $evidence->ambiguousCreate();
            throw $exception;
        }
        $remoteId = $created->remoteId;
        $evidence->created($remoteId);

        $read = $connection->contactReads()->find($remoteId);
        expect($read->remoteId)->toBe($remoteId)
            ->and(oauthFixtureField($read, 'email'))->toBe($email)
            ->and(oauthFixtureField($read, 'firstname'))->toBe('OAuthBefore')
            ->and(oauthFixtureField($read, 'lastname'))->toBe('LifecycleFixture')
            ->and(oauthFixtureField($read, 'phone'))->toBe('+36 30 555 0101')
            ->and($read->doNotContact->presence)->toBe(ValuePresence::Value);
        $initialDnc = $read->doNotContact->value();

        $discovered = $connection->contactDiscovery()->exactEmail('  '.strtoupper($email).'  ');
        expect($discovered->outcome)->toBe(ContactDiscoveryOutcome::Unique)
            ->and($discovered->uniqueContact()?->remoteId)->toBe($remoteId);

        $connection->contactWrites()->update($remoteId, ContactFields::fromArray(
            ['firstname' => 'OAuthAfter'],
            ['firstname'],
        ));
        $patched = $connection->contactReads()->find($remoteId);
        expect(oauthFixtureField($patched, 'firstname'))->toBe('OAuthAfter')
            ->and(oauthFixtureField($patched, 'email'))->toBe($email)
            ->and(oauthFixtureField($patched, 'lastname'))->toBe('LifecycleFixture')
            ->and(oauthFixtureField($patched, 'phone'))->toBe('+36 30 555 0101');

        $connection->contactWrites()->update($remoteId, ContactFields::fromArray(
            ['phone' => ''],
            ['phone'],
        ));
        $cleared = $connection->contactReads()->find($remoteId);
        $clearedPhone = $cleared->field('phone');
        $phoneIsCleared = $clearedPhone->presence === ValuePresence::Null
            || ($clearedPhone->presence === ValuePresence::Value && $clearedPhone->value() === '');
        expect($phoneIsCleared)->toBeTrue()
            ->and(oauthFixtureField($cleared, 'firstname'))->toBe('OAuthAfter')
            ->and(oauthFixtureField($cleared, 'email'))->toBe($email)
            ->and(oauthFixtureField($cleared, 'lastname'))->toBe('LifecycleFixture')
            ->and($cleared->doNotContact->presence)->toBe(ValuePresence::Value)
            ->and($cleared->doNotContact->value())->toEqual($initialDnc);

        expect(fn () => $connection->contactWrites()->create(ContactFields::fromArray([
            'email' => strtoupper($email),
            'firstname' => 'RejectedDuplicate',
        ], ['email', 'firstname'])))->toThrow(ContactAlreadyExists::class);
        $afterDuplicate = $connection->contactDiscovery()->exactEmail($email);
        expect($afterDuplicate->outcome)->toBe(ContactDiscoveryOutcome::Unique)
            ->and($afterDuplicate->uniqueContact()?->remoteId)->toBe($remoteId);

        if ($configuration->staticSegmentId !== null) {
            $segmentId = $configuration->staticSegmentId;
            $beforeMemberships = oauthFixtureMembershipIds($connection, $remoteId);
            expect($beforeMemberships)->not->toContain($segmentId);
            $connection->contactWrites()->addToSegment($remoteId, $segmentId);
            $segmentAdded = true;
            $withSegment = oauthFixtureMembershipIds($connection, $remoteId);
            $expectedMemberships = array_values(array_unique(array_merge($beforeMemberships, [$segmentId])));
            sort($expectedMemberships);
            expect($withSegment)->toEqual($expectedMemberships);
            $connection->contactWrites()->removeFromSegment($remoteId, $segmentId);
            $segmentAdded = false;
            expect(oauthFixtureMembershipIds($connection, $remoteId))->toEqual($beforeMemberships);
        }
    } catch (Throwable $exception) {
        $failure = $exception;
    }

    if (is_int($remoteId)) {
        try {
            if ($segmentAdded && $configuration->staticSegmentId !== null) {
                $connection->contactWrites()->removeFromSegment($remoteId, $configuration->staticSegmentId);
            }
            deleteOwnedOAuthFixture($connection, $remoteId, $email, $evidence);
        } catch (Throwable $cleanupFailure) {
            $evidence->cleanupFailed();
            if ($failure === null) {
                throw $cleanupFailure;
            }
        }
    }

    if ($failure !== null) {
        throw $failure;
    }
});
