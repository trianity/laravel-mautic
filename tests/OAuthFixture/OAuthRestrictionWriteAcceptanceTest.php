<?php

declare(strict_types=1);

use Trianity\LaravelMautic\Data\Contact;
use Trianity\LaravelMautic\Data\DoNotContactEntry;
use Trianity\LaravelMautic\Data\DoNotContactReason;
use Trianity\LaravelMautic\Data\ValuePresence;
use Trianity\LaravelMautic\Exceptions\AmbiguousMutationOutcome;
use Trianity\LaravelMautic\Exceptions\ContactNotFound;
use Trianity\LaravelMautic\MauticConnection;
use Trianity\LaravelMautic\OAuth\SystemClock;
use Trianity\LaravelMautic\Restriction\ManualEmailBlock;
use Trianity\LaravelMautic\Restriction\RestrictionMutationOutcome;
use Trianity\LaravelMautic\Tests\Support\OAuthAcceptanceHarness;
use Trianity\LaravelMautic\Tests\Support\OAuthAcceptanceRequestBudget;
use Trianity\LaravelMautic\Tests\Support\OAuthFixtureConfiguration;
use Trianity\LaravelMautic\Tests\Support\OAuthRestrictionWriteConfiguration;
use Trianity\LaravelMautic\Tests\Support\OAuthRestrictionWriteEvidence;
use Trianity\LaravelMautic\Write\ContactFields;

/** @return list<DoNotContactEntry> */
function oauthRestrictionEmailEntries(Contact $contact): array
{
    expect($contact->doNotContact->presence)->toBe(ValuePresence::Value);

    return array_values(array_filter(
        $contact->doNotContact->value(),
        static fn (DoNotContactEntry $entry): bool => $entry->channel === 'email',
    ));
}

function oauthRestrictionCreateFixture(MauticConnection $connection, string $email): int
{
    try {
        return $connection->contactWrites()->create(ContactFields::fromArray([
            'email' => $email,
            'firstname' => 'RestrictionWrite',
            'lastname' => 'DisposableFixture',
        ], ['email', 'firstname', 'lastname']))->remoteId;
    } catch (AmbiguousMutationOutcome $exception) {
        throw new RuntimeException('Restriction fixture creation was ambiguous; no discovery or cleanup was attempted.', previous: $exception);
    }
}

function oauthRestrictionRawSetup(MauticConnection $connection, int $remoteId, int $reason, string $comments): void
{
    $response = $connection->contacts()->addDnc($remoteId, 'email', $reason, null, $comments);
    if (! is_array($response) || array_key_exists('errors', $response)) {
        throw new RuntimeException('Raw restriction fixture setup failed.');
    }
}

function oauthRestrictionExpectedSanitizedComments(ManualEmailBlock $operation): string
{
    return str_replace("\n", '&#10;', $operation->comments());
}

function oauthRestrictionDeleteOwnedFixture(MauticConnection $connection, int $remoteId, string $email): void
{
    $stored = $connection->contactReads()->find($remoteId);
    $storedEmail = $stored->field('email');
    if ($stored->remoteId !== $remoteId || $storedEmail->presence !== ValuePresence::Value
        || ! is_string($storedEmail->value()) || ! hash_equals($email, $storedEmail->value())) {
        throw new RuntimeException('Refusing to delete a restriction fixture without exact current ownership evidence.');
    }

    $response = $connection->contacts()->delete($remoteId);
    if (! is_array($response) || array_key_exists('errors', $response)) {
        throw new RuntimeException('The owned restriction fixture could not be deleted safely.');
    }

    expect(fn () => $connection->contactReads()->find($remoteId))->toThrow(ContactNotFound::class);
}

/**
 * @param  callable(MauticConnection, int): array<string, bool|int|string>  $scenario
 * @return array<string, bool|int|string>
 */
function runOAuthRestrictionScenario(
    OAuthFixtureConfiguration $configuration,
    string $name,
    OAuthRestrictionWriteEvidence $evidenceWriter,
    array &$evidence,
    callable $scenario,
): array {
    $budget = new OAuthAcceptanceRequestBudget(operationLimit: 16, cleanupLimit: 3);
    $connection = (new OAuthAcceptanceHarness($configuration->oauth, requestBudget: $budget))->connection();
    $email = 'lm-live-mautic721.restriction.'.bin2hex(random_bytes(12)).'@example.test';
    $remoteId = null;
    $failure = null;
    $result = [];

    try {
        $remoteId = oauthRestrictionCreateFixture($connection, $email);
        $result = $scenario($connection, $remoteId);
    } catch (Throwable $exception) {
        $failure = $budget->operationWasRejected()
            ? new RuntimeException("{$name} restriction scenario exhausted its operation request budget.")
            : $exception;
    }

    $budget->beginCleanup();
    $cleanup = 'not_required';
    $cleanupFailure = null;
    if (is_int($remoteId)) {
        try {
            oauthRestrictionDeleteOwnedFixture($connection, $remoteId, $email);
            $cleanup = 'deleted';
        } catch (Throwable $exception) {
            $cleanup = 'failed';
            $cleanupFailure = $budget->cleanupWasRejected()
                ? new RuntimeException("{$name} restriction scenario exhausted its cleanup request budget.")
                : $exception;
        }
    }

    $result['operation_requests'] = $budget->operationAttempts();
    $result['cleanup_requests'] = $budget->cleanupAttempts();
    $result['cleanup'] = $cleanup;

    if ($failure !== null) {
        $result['scenario_status'] = $failure->getPrevious() instanceof AmbiguousMutationOutcome
            ? 'ambiguous_create'
            : 'failed';
        $evidence[$name] = $result;
        $evidenceWriter->write($evidence, status: 'failed');

        throw $failure;
    }
    if ($cleanupFailure !== null) {
        $result['scenario_status'] = 'cleanup_failed';
        $evidence[$name] = $result;
        $evidenceWriter->write($evidence, status: 'failed');

        throw $cleanupFailure;
    }

    return $result;
}

it('verifies isolated restriction write scenarios and owned cleanup', function () {
    if (! OAuthRestrictionWriteConfiguration::enabledFromEnvironment()) {
        test()->markTestSkipped('Set MAUTIC_OAUTH_RESTRICTION_WRITE_TESTS=1 for restriction write scenarios.');
    }

    $configuration = OAuthRestrictionWriteConfiguration::fromEnvironment()->fixture;
    $clock = new SystemClock;
    $evidence = [];
    $evidenceWriter = new OAuthRestrictionWriteEvidence;

    $evidence['clean'] = runOAuthRestrictionScenario($configuration, 'clean', $evidenceWriter, $evidence, function (
        MauticConnection $connection,
        int $remoteId,
    ) use ($clock): array {
        expect(oauthRestrictionEmailEntries($connection->contactReads()->find($remoteId)))->toBe([]);

        $operation = ManualEmailBlock::record('Acceptance manual suppression Ω', $clock);
        $blocked = $connection->restrictionWrites()->blockEmail($remoteId, $operation);
        expect($blocked->outcome)->toBe(RestrictionMutationOutcome::ManualPresent)
            ->and($blocked->submittedCommentsObserved)->toBeFalse();
        $stored = oauthRestrictionEmailEntries($connection->contactReads()->find($remoteId));
        expect($stored)->toHaveCount(1)
            ->and($stored[0]->knownReason())->toBe(DoNotContactReason::Manual)
            ->and($stored[0]->comments->presence)->toBe(ValuePresence::Value)
            ->and($stored[0]->comments->value())->toBe(oauthRestrictionExpectedSanitizedComments($operation));

        $repeatedBlock = $connection->restrictionWrites()->blockEmail($remoteId, $operation);
        expect($repeatedBlock->outcome)->toBe(RestrictionMutationOutcome::ManualPresent)
            ->and($repeatedBlock->submittedCommentsObserved)->toBeFalse();

        $released = $connection->restrictionWrites()->releaseEmailRestriction($remoteId);
        expect($released->outcome)->toBe(RestrictionMutationOutcome::Released)
            ->and(oauthRestrictionEmailEntries($connection->contactReads()->find($remoteId)))->toBe([]);
        $repeatedRelease = $connection->restrictionWrites()->releaseEmailRestriction($remoteId);
        expect($repeatedRelease->outcome)->toBe(RestrictionMutationOutcome::NoMatchingRestriction)
            ->and(oauthRestrictionEmailEntries($connection->contactReads()->find($remoteId)))->toBe([]);

        return [
            'first_block' => $blocked->outcome->value,
            'repeated_block' => $repeatedBlock->outcome->value,
            'comments_exact' => $blocked->submittedCommentsObserved,
            'comments_representation' => 'line_feeds_html_numeric_entities',
            'release' => $released->outcome->value,
            'repeated_release' => $repeatedRelease->outcome->value,
        ];
    });

    $evidence['existing_manual'] = runOAuthRestrictionScenario(
        $configuration,
        'existing_manual',
        $evidenceWriter,
        $evidence,
        function (
            MauticConnection $connection,
            int $remoteId,
        ) use ($clock): array {
            oauthRestrictionRawSetup($connection, $remoteId, 3, 'fixture original manual');
            $before = oauthRestrictionEmailEntries($connection->contactReads()->find($remoteId));
            expect($before)->toHaveCount(1)
                ->and($before[0]->knownReason())->toBe(DoNotContactReason::Manual);
            $originalComments = $before[0]->comments;

            $result = $connection->restrictionWrites()->blockEmail(
                $remoteId,
                ManualEmailBlock::record('different acceptance explanation', $clock),
            );
            $after = oauthRestrictionEmailEntries($connection->contactReads()->find($remoteId));
            expect($result->outcome)->toBe(RestrictionMutationOutcome::ManualPresent)
                ->and($result->submittedCommentsObserved)->toBeFalse()
                ->and($after)->toHaveCount(1)
                ->and($after[0]->comments)->toEqual($originalComments);

            return [
                'block' => $result->outcome->value,
                'submitted_comments_observed' => $result->submittedCommentsObserved,
                'original_metadata_retained' => true,
            ];
        },
    );

    $evidence['existing_bounce'] = runOAuthRestrictionScenario(
        $configuration,
        'existing_bounce',
        $evidenceWriter,
        $evidence,
        function (
            MauticConnection $connection,
            int $remoteId,
        ) use ($clock): array {
            oauthRestrictionRawSetup($connection, $remoteId, 2, 'fixture bounce');
            expect(oauthRestrictionEmailEntries($connection->contactReads()->find($remoteId))[0]->knownReason())
                ->toBe(DoNotContactReason::Bounced);

            $blocked = $connection->restrictionWrites()->blockEmail(
                $remoteId,
                $operation = ManualEmailBlock::record('replace fixture bounce administratively', $clock),
            );
            expect($blocked->outcome)->toBe(RestrictionMutationOutcome::ManualPresent)
                ->and($blocked->submittedCommentsObserved)->toBeFalse();
            $stored = oauthRestrictionEmailEntries($connection->contactReads()->find($remoteId));
            expect($stored[0]->knownReason())->toBe(DoNotContactReason::Manual)
                ->and($stored[0]->comments->value())->toBe(oauthRestrictionExpectedSanitizedComments($operation));
            $released = $connection->restrictionWrites()->releaseEmailRestriction($remoteId);
            expect($released->outcome)->toBe(RestrictionMutationOutcome::Released)
                ->and(oauthRestrictionEmailEntries($connection->contactReads()->find($remoteId)))->toBe([]);

            return [
                'block' => $blocked->outcome->value,
                'comments_exact' => $blocked->submittedCommentsObserved,
                'comments_representation' => 'line_feeds_html_numeric_entities',
                'release' => $released->outcome->value,
            ];
        },
    );

    $evidence['existing_unsubscribe'] = runOAuthRestrictionScenario(
        $configuration,
        'existing_unsubscribe',
        $evidenceWriter,
        $evidence,
        function (
            MauticConnection $connection,
            int $remoteId,
        ) use ($clock): array {
            oauthRestrictionRawSetup($connection, $remoteId, 1, 'fixture unsubscribe');
            expect(oauthRestrictionEmailEntries($connection->contactReads()->find($remoteId))[0]->knownReason())
                ->toBe(DoNotContactReason::Unsubscribed);

            $blocked = $connection->restrictionWrites()->blockEmail(
                $remoteId,
                ManualEmailBlock::record('attempt manual over unsubscribe', $clock),
            );
            $retained = oauthRestrictionEmailEntries($connection->contactReads()->find($remoteId));
            expect($blocked->outcome)->toBe(RestrictionMutationOutcome::ExistingRestrictionRetained)
                ->and($blocked->submittedCommentsObserved)->toBeFalse()
                ->and($retained[0]->knownReason())->toBe(DoNotContactReason::Unsubscribed);
            $released = $connection->restrictionWrites()->releaseEmailRestriction($remoteId);
            expect($released->outcome)->toBe(RestrictionMutationOutcome::Released)
                ->and(oauthRestrictionEmailEntries($connection->contactReads()->find($remoteId)))->toBe([]);

            return [
                'block' => $blocked->outcome->value,
                'unsubscribe_retained' => true,
                'release' => $released->outcome->value,
                'consent_restored' => false,
            ];
        },
    );

    $path = $evidenceWriter->write($evidence);
    expect(is_file($path))->toBeTrue();
});
