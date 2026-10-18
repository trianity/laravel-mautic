<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Support;

use Mautic\Api\Contacts;
use Mautic\Api\Segments;
use Trianity\LaravelMautic\Contracts\ContactWriter;
use Trianity\LaravelMautic\Data\Contact;
use Trianity\LaravelMautic\Data\ContactBatch;
use Trianity\LaravelMautic\Exceptions\ContactAlreadyExists;
use Trianity\LaravelMautic\Exceptions\InvalidWriteRequest;
use Trianity\LaravelMautic\Read\ReadOperation;
use Trianity\LaravelMautic\Write\ContactFields;
use Trianity\LaravelMautic\Write\ContactUpdate;
use Trianity\LaravelMautic\Write\MutationOperation;

final readonly class SdkContactWriter implements ContactWriter
{
    private ContactResponseMapper $mapper;

    public function __construct(
        private Contacts $contacts,
        private Segments $segments,
        private string $connection,
        private SdkResponseBoundary $boundary,
    ) {
        $this->mapper = new ContactResponseMapper($connection);
    }

    public function create(ContactFields $fields): Contact
    {
        $this->rejectKnownDuplicate($fields, MutationOperation::CreateContact);
        $operation = MutationOperation::CreateContact;
        $response = $this->boundary->successfulWrite(
            $this->contacts->create($fields->toPayload()),
            $operation,
            $this->connection,
        );

        if (! array_key_exists('contact', $response)) {
            $this->boundary->unexpectedWrite($operation, $this->connection);
        }

        return $this->mapper->contact($response['contact'], $operation);
    }

    public function update(int $remoteId, ContactFields $fields): Contact
    {
        $this->validateRemoteId($remoteId);
        $operation = MutationOperation::UpdateContact;
        $response = $this->boundary->successfulWrite(
            $this->contacts->edit($remoteId, $fields->toPayload(), false),
            $operation,
            $this->connection,
        );

        if (! array_key_exists('contact', $response)) {
            $this->boundary->unexpectedWrite($operation, $this->connection);
        }

        return $this->mapper->contact($response['contact'], $operation);
    }

    public function createBatch(ContactFields ...$contacts): ContactBatch
    {
        if ($contacts === []) {
            throw new InvalidWriteRequest('A contact creation batch cannot be empty.');
        }

        $contacts = array_values($contacts);
        $this->rejectDuplicateEmailsWithinBatch($contacts);
        foreach ($contacts as $fields) {
            $this->rejectKnownDuplicate($fields, MutationOperation::CreateContactsBatch);
        }

        $operation = MutationOperation::CreateContactsBatch;
        $response = $this->boundary->successfulWrite(
            $this->contacts->createBatch(array_map(
                static fn (ContactFields $fields): array => $fields->toPayload(),
                $contacts,
            )),
            $operation,
            $this->connection,
        );

        return $this->mapBatch($response, count($contacts), $operation);
    }

    public function updateBatch(ContactUpdate ...$contacts): ContactBatch
    {
        if ($contacts === []) {
            throw new InvalidWriteRequest('A contact update batch cannot be empty.');
        }

        $payloads = [];
        foreach ($contacts as $update) {
            $this->validateRemoteId($update->remoteId);
            $payloads[] = ['id' => $update->remoteId] + $update->fields->toPayload();
        }

        $operation = MutationOperation::UpdateContactsBatch;
        $response = $this->boundary->successfulWrite(
            $this->contacts->editBatch($payloads, false),
            $operation,
            $this->connection,
        );

        return $this->mapBatch($response, count($contacts), $operation);
    }

    public function addToSegment(int $remoteId, int $segmentId): void
    {
        $this->segmentCommand($remoteId, $segmentId, MutationOperation::AddContactToSegment);
    }

    public function removeFromSegment(int $remoteId, int $segmentId): void
    {
        $this->segmentCommand($remoteId, $segmentId, MutationOperation::RemoveContactFromSegment);
    }

    private function rejectKnownDuplicate(ContactFields $fields, MutationOperation $operation): void
    {
        if (! $fields->has('email')) {
            return;
        }

        $email = $fields->value('email');
        if (! is_string($email) || trim($email) === '') {
            return;
        }

        $response = $this->boundary->successful(
            $this->contacts->getList('email:'.$email, 0, 2),
            ReadOperation::SearchContacts,
            $this->connection,
        );

        if (! is_int($response['total'] ?? null) || ! is_array($response['contacts'] ?? null)) {
            $this->boundary->unexpected(ReadOperation::SearchContacts, $this->connection);
        }

        if ($response['total'] > 0) {
            throw new ContactAlreadyExists($this->connection, $operation);
        }
    }

    /** @param list<ContactFields> $contacts */
    private function rejectDuplicateEmailsWithinBatch(array $contacts): void
    {
        $seen = [];
        foreach ($contacts as $fields) {
            $email = $fields->value('email');
            if (! $fields->has('email') || ! is_string($email) || trim($email) === '') {
                continue;
            }

            $normalized = strtolower(trim($email));
            if (isset($seen[$normalized])) {
                throw new ContactAlreadyExists($this->connection, MutationOperation::CreateContactsBatch);
            }

            $seen[$normalized] = true;
        }
    }

    private function segmentCommand(int $remoteId, int $segmentId, MutationOperation $operation): void
    {
        $this->validateRemoteId($remoteId);
        $this->validateRemoteId($segmentId);
        $response = $operation === MutationOperation::AddContactToSegment
            ? $this->segments->addContact($segmentId, $remoteId)
            : $this->segments->removeContact($segmentId, $remoteId);
        $this->boundary->successfulWrite($response, $operation, $this->connection);
    }

    /** @param array<string, mixed> $response */
    private function mapBatch(array $response, int $expectedCount, MutationOperation $operation): ContactBatch
    {
        if (! is_array($response['contacts'] ?? null)
            || count($response['contacts']) !== $expectedCount) {
            $this->boundary->unexpectedWrite($operation, $this->connection);
        }

        $contacts = [];
        foreach (array_values($response['contacts']) as $index => $item) {
            if (! is_array($item)) {
                $this->boundary->unexpectedWrite($operation, $this->connection, $index);
            }

            $item = $this->boundary->successfulWrite($item, $operation, $this->connection, $index);
            $contacts[] = $this->mapper->contact($item, $operation);
        }

        return new ContactBatch($contacts);
    }

    private function validateRemoteId(int $remoteId): void
    {
        if ($remoteId < 1) {
            throw new InvalidWriteRequest('Remote IDs must be positive integers.');
        }
    }
}
