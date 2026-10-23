<?php

declare(strict_types=1);

use Trianity\LaravelMautic\Data\ValuePresence;
use Trianity\LaravelMautic\Exceptions\AmbiguousMutationOutcome;
use Trianity\LaravelMautic\Exceptions\ContactNotFound;
use Trianity\LaravelMautic\Identity\ContactDiscoveryOutcome;
use Trianity\LaravelMautic\MauticConnection;
use Trianity\LaravelMautic\Tests\Support\OAuthAcceptanceHarness;
use Trianity\LaravelMautic\Tests\Support\OAuthAcceptanceRequestBudget;
use Trianity\LaravelMautic\Tests\Support\OAuthFixtureConfiguration;
use Trianity\LaravelMautic\Tests\Support\OAuthFixtureEvidence;
use Trianity\LaravelMautic\Tests\Support\OAuthStaticSegmentPreflight;
use Trianity\LaravelMautic\Write\ContactFields;

/** @return list<int> */
function oauthStaticSegmentMembershipIds(MauticConnection $connection, int $remoteId): array
{
    $ids = array_column($connection->contactReads()->segments($remoteId)->memberships, 'remoteId');
    sort($ids);

    return $ids;
}

function deleteOwnedOAuthStaticSegmentFixture(
    MauticConnection $connection,
    int $remoteId,
    string $email,
    OAuthFixtureEvidence $evidence,
): void {
    $stored = $connection->contactReads()->find($remoteId);
    $storedEmail = $stored->field('email');
    if ($stored->remoteId !== $remoteId || $storedEmail->presence !== ValuePresence::Value
        || ! is_string($storedEmail->value()) || ! hash_equals($email, $storedEmail->value())) {
        throw new RuntimeException('Refusing to delete a segment fixture without exact current ownership evidence.');
    }

    $response = $connection->contacts()->delete($remoteId);
    if (! is_array($response) || array_key_exists('errors', $response)) {
        throw new RuntimeException('The owned OAuth segment fixture could not be deleted safely.');
    }

    expect(fn () => $connection->contactReads()->find($remoteId))->toThrow(ContactNotFound::class);
    $evidence->deleted();
}

it('verifies idempotent static segment membership through the OAuth fixture', function () {
    if (getenv('MAUTIC_OAUTH_FIXTURE_TESTS') !== '1') {
        test()->markTestSkipped('Set MAUTIC_OAUTH_FIXTURE_TESTS=1 for OAuth static segment membership acceptance.');
    }

    $configuration = OAuthFixtureConfiguration::staticSegmentFromEnvironment();
    $segmentId = $configuration->staticSegmentId;
    if ($segmentId === null) {
        throw new RuntimeException('The required static segment ID was not retained.');
    }

    $requestBudget = new OAuthAcceptanceRequestBudget;
    $harness = new OAuthAcceptanceHarness($configuration->oauth, requestBudget: $requestBudget);
    $connection = $harness->connection();

    (new OAuthStaticSegmentPreflight)->validate($connection->api('segments')->get($segmentId), $segmentId);

    $runId = bin2hex(random_bytes(12));
    $email = 'lm-live-mautic721.segment.'.$runId.'@example.test';
    $evidence = new OAuthFixtureEvidence($runId, $email);
    $remoteId = null;
    $membershipMayBePresent = false;
    $failure = null;

    try {
        expect($connection->contactDiscovery()->exactEmail($email)->outcome)
            ->toBe(ContactDiscoveryOutcome::None);

        try {
            $created = $connection->contactWrites()->create(ContactFields::fromArray([
                'email' => $email,
                'firstname' => 'OAuthSegment',
                'lastname' => 'MembershipFixture',
            ], ['email', 'firstname', 'lastname']));
        } catch (AmbiguousMutationOutcome $exception) {
            $evidence->ambiguousCreate();
            throw $exception;
        }
        $remoteId = $created->remoteId;
        $evidence->created($remoteId);

        $beforeContact = $connection->contactReads()->find($remoteId);
        expect($beforeContact->doNotContact->presence)->toBe(ValuePresence::Value);
        $initialDnc = $beforeContact->doNotContact->value();

        $initialMemberships = oauthStaticSegmentMembershipIds($connection, $remoteId);
        expect($initialMemberships)->not->toContain($segmentId);
        $expectedWithTarget = array_values(array_unique(array_merge($initialMemberships, [$segmentId])));
        sort($expectedWithTarget);

        $membershipMayBePresent = true;
        $connection->contactWrites()->addToSegment($remoteId, $segmentId);
        expect(oauthStaticSegmentMembershipIds($connection, $remoteId))->toEqual($expectedWithTarget);

        $connection->contactWrites()->addToSegment($remoteId, $segmentId);
        expect(oauthStaticSegmentMembershipIds($connection, $remoteId))->toEqual($expectedWithTarget);

        $connection->contactWrites()->removeFromSegment($remoteId, $segmentId);
        expect(oauthStaticSegmentMembershipIds($connection, $remoteId))->toEqual($initialMemberships);

        $connection->contactWrites()->removeFromSegment($remoteId, $segmentId);
        expect(oauthStaticSegmentMembershipIds($connection, $remoteId))->toEqual($initialMemberships);
        $membershipMayBePresent = false;

        $afterContact = $connection->contactReads()->find($remoteId);
        expect($afterContact->doNotContact->presence)->toBe(ValuePresence::Value)
            ->and($afterContact->doNotContact->value())->toEqual($initialDnc)
            ->and($requestBudget->operationAttempts())->toBeLessThanOrEqual($requestBudget->operationLimit());
    } catch (Throwable $exception) {
        $failure = $requestBudget->operationWasRejected()
            ? new RuntimeException('OAuth acceptance operation request budget exhausted.')
            : $exception;
    }

    $requestBudget->beginCleanup();
    $cleanupFailure = null;
    if (is_int($remoteId)) {
        if ($membershipMayBePresent) {
            try {
                $connection->contactWrites()->removeFromSegment($remoteId, $segmentId);
                $evidence->membershipCleanup('removed');
            } catch (Throwable $exception) {
                $evidence->membershipCleanup('failed');
                $cleanupFailure = $requestBudget->cleanupWasRejected()
                    ? new RuntimeException('OAuth acceptance cleanup request budget exhausted.')
                    : $exception;
            }
        } else {
            $evidence->membershipCleanup('already_absent');
        }

        try {
            deleteOwnedOAuthStaticSegmentFixture($connection, $remoteId, $email, $evidence);
        } catch (Throwable $exception) {
            $evidence->cleanupFailed();
            $cleanupFailure ??= $requestBudget->cleanupWasRejected()
                ? new RuntimeException('OAuth acceptance cleanup request budget exhausted.')
                : $exception;
        }
    }
    $evidence->requestCounts(
        $requestBudget->operationAttempts(),
        $requestBudget->cleanupAttempts(),
    );

    if ($failure !== null) {
        throw $failure;
    }
    if ($cleanupFailure !== null) {
        throw $cleanupFailure;
    }
});
