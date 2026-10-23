<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Support;

use Mautic\Api\Contacts;
use Throwable;
use Trianity\LaravelMautic\Contracts\ContactRestrictionWriter;
use Trianity\LaravelMautic\Data\Contact;
use Trianity\LaravelMautic\Data\DoNotContactEntry;
use Trianity\LaravelMautic\Data\DoNotContactReason;
use Trianity\LaravelMautic\Data\ValuePresence;
use Trianity\LaravelMautic\Exceptions\InvalidWriteRequest;
use Trianity\LaravelMautic\Exceptions\MauticWriteException;
use Trianity\LaravelMautic\Exceptions\RestrictionStateNotVerified;
use Trianity\LaravelMautic\Read\ReadOperation;
use Trianity\LaravelMautic\Restriction\EmailRestrictionMutationResult;
use Trianity\LaravelMautic\Restriction\ManualEmailBlock;
use Trianity\LaravelMautic\Restriction\RestrictionMutationOutcome;
use Trianity\LaravelMautic\Write\MutationOperation;

final readonly class SdkContactRestrictionWriter implements ContactRestrictionWriter
{
    private ContactResponseMapper $mapper;

    public function __construct(
        private Contacts $contacts,
        private string $connection,
        private SdkResponseBoundary $boundary,
    ) {
        $this->mapper = new ContactResponseMapper($connection);
    }

    public function blockEmail(int $remoteId, ManualEmailBlock $operation): EmailRestrictionMutationResult
    {
        $this->validateRemoteId($remoteId);
        $mutation = MutationOperation::BlockContactEmail;
        $rawResponse = $this->contacts->addDnc(
            $remoteId,
            'email',
            Contacts::MANUAL,
            null,
            $operation->comments(),
        );
        $response = $this->boundary->successfulWrite(
            $rawResponse,
            $mutation,
            $this->connection,
            retryAfterSeconds: SdkResponseMetadata::retryAfterForResponse($rawResponse, $this->contacts),
        );
        [$contact, $restrictions] = $this->verifiedState($response, $remoteId, $mutation);
        $manual = array_values(array_filter(
            $restrictions,
            static fn (DoNotContactEntry $entry): bool => $entry->knownReason() === DoNotContactReason::Manual,
        ));
        $commentsObserved = array_any(
            $manual,
            static fn (DoNotContactEntry $entry): bool => $entry->comments->presence === ValuePresence::Value
                && is_string($entry->comments->value())
                && hash_equals($operation->comments(), $entry->comments->value()),
        );

        $outcome = match (true) {
            $manual !== [] => RestrictionMutationOutcome::ManualPresent,
            $restrictions !== [] => RestrictionMutationOutcome::ExistingRestrictionRetained,
            default => RestrictionMutationOutcome::NoRestrictionPresent,
        };

        return new EmailRestrictionMutationResult(
            $outcome,
            $contact,
            $restrictions,
            $commentsObserved,
            null,
        );
    }

    public function releaseEmailRestriction(int $remoteId): EmailRestrictionMutationResult
    {
        $this->validateRemoteId($remoteId);
        $operation = MutationOperation::ReleaseContactEmailRestriction;
        $rawResponse = $this->contacts->removeDnc($remoteId, 'email');
        $response = $this->boundary->successfulWrite(
            $rawResponse,
            $operation,
            $this->connection,
            retryAfterSeconds: SdkResponseMetadata::retryAfterForResponse($rawResponse, $this->contacts),
        );
        $recordFound = $response['recordFound'] ?? null;
        if (! is_bool($recordFound)) {
            throw new RestrictionStateNotVerified($operation, $this->connection);
        }

        [$contact, $restrictions] = $this->verifiedState($response, $remoteId, $operation);
        $outcome = match (true) {
            ! $recordFound => RestrictionMutationOutcome::NoMatchingRestriction,
            $restrictions === [] => RestrictionMutationOutcome::Released,
            default => RestrictionMutationOutcome::RestrictionRemains,
        };

        return new EmailRestrictionMutationResult($outcome, $contact, $restrictions, null, $recordFound);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array{Contact, list<DoNotContactEntry>}
     */
    private function verifiedState(array $response, int $remoteId, MutationOperation $operation): array
    {
        try {
            if (! array_key_exists('contact', $response)) {
                throw new RestrictionStateNotVerified($operation, $this->connection);
            }

            $contact = $this->mapper->contact($response['contact'], $operation);

            return [$contact, $this->emailRestrictions($contact, $operation)];
        } catch (MauticWriteException) {
            // The stock mutation response can use a different embedded-contact shape.
        }

        try {
            $readOperation = ReadOperation::FindContact;
            $readResponse = $this->boundary->successful(
                $this->contacts->get($remoteId),
                $readOperation,
                $this->connection,
            );
            if (! array_key_exists('contact', $readResponse)) {
                throw new RestrictionStateNotVerified($operation, $this->connection);
            }
            $contact = $this->mapper->contact($readResponse['contact'], $readOperation);
            if ($contact->doNotContact->presence !== ValuePresence::Value) {
                throw new RestrictionStateNotVerified($operation, $this->connection);
            }
            $entries = $contact->doNotContact->value();
            if (! is_array($entries)) {
                throw new RestrictionStateNotVerified($operation, $this->connection);
            }

            return [$contact, array_values(array_filter(
                $entries,
                static fn (DoNotContactEntry $entry): bool => $entry->channel === 'email',
            ))];
        } catch (Throwable) {
            throw new RestrictionStateNotVerified($operation, $this->connection);
        }
    }

    /** @return list<DoNotContactEntry> */
    private function emailRestrictions(Contact $contact, MutationOperation $operation): array
    {
        if ($contact->doNotContact->presence !== ValuePresence::Value) {
            throw new RestrictionStateNotVerified($operation, $this->connection);
        }

        $entries = $contact->doNotContact->value();
        if (! is_array($entries)) {
            throw new RestrictionStateNotVerified($operation, $this->connection);
        }

        return array_values(array_filter(
            $entries,
            static fn (DoNotContactEntry $entry): bool => $entry->channel === 'email',
        ));
    }

    private function validateRemoteId(int $remoteId): void
    {
        if ($remoteId < 1) {
            throw new InvalidWriteRequest('Remote IDs must be positive integers.');
        }
    }
}
