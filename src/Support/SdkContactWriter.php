<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Support;

use Mautic\Api\Contacts;
use Mautic\Api\Segments;
use Mautic\QueryBuilder\QueryBuilder;
use Trianity\LaravelMautic\Contracts\ContactWriter;
use Trianity\LaravelMautic\Data\Contact;
use Trianity\LaravelMautic\Data\ContactBatch;
use Trianity\LaravelMautic\Data\ContactBatchItem;
use Trianity\LaravelMautic\Exceptions\ContactAlreadyExists;
use Trianity\LaravelMautic\Exceptions\InvalidWriteRequest;
use Trianity\LaravelMautic\Exceptions\MauticWriteException;
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
        $rawResponse = $this->contacts->create($fields->toPayload());
        $response = $this->boundary->successfulWrite(
            $rawResponse,
            $operation,
            $this->connection,
            retryAfterSeconds: $this->retryAfterSeconds($this->contacts->getResponseHeaders()),
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
        $rawResponse = $this->contacts->edit($remoteId, $fields->toPayload(), false);
        $response = $this->boundary->successfulWrite(
            $rawResponse,
            $operation,
            $this->connection,
            retryAfterSeconds: $this->retryAfterSeconds($this->contacts->getResponseHeaders()),
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
        $response = $this->contacts->createBatch(array_map(
            static fn (ContactFields $fields): array => $fields->toPayload(),
            $contacts,
        ));

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
        $response = $this->contacts->editBatch($payloads, false);

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

        $normalizedEmail = ContactEmailNormalizer::normalize($email);
        $query = new QueryBuilder;
        $query->addWhere($query->expr()->eq('email', $normalizedEmail));
        $start = 0;

        do {
            $rawResponse = $this->contacts->getCustomList($query, $start, 100);
            if (! is_array($rawResponse)) {
                $this->boundary->unexpected(ReadOperation::SearchContacts, $this->connection);
            }

            $response = $this->boundary->successful(
                $rawResponse,
                ReadOperation::SearchContacts,
                $this->connection,
                $this->retryAfterSeconds($this->contacts->getResponseHeaders()),
            );

            if (! is_int($response['total'] ?? null) || ! is_array($response['contacts'] ?? null)) {
                $this->boundary->unexpected(ReadOperation::SearchContacts, $this->connection);
            }

            foreach ($response['contacts'] as $contact) {
                $returnedEmail = $this->contactEmail($contact);
                if ($returnedEmail === null) {
                    $this->boundary->unexpected(ReadOperation::SearchContacts, $this->connection);
                }

                if (ContactEmailNormalizer::normalize($returnedEmail) === $normalizedEmail) {
                    throw new ContactAlreadyExists($this->connection, $operation);
                }
            }

            $count = count($response['contacts']);
            $start += $count;
            if ($count === 0 && $start < $response['total']) {
                $this->boundary->unexpected(ReadOperation::SearchContacts, $this->connection);
            }
        } while ($start < $response['total']);
    }

    private function contactEmail(mixed $contact): ?string
    {
        if (! is_array($contact)) {
            return null;
        }

        $allValue = $contact['fields']['all']['email'] ?? null;
        if (is_string($allValue)) {
            return $allValue;
        }

        $coreValue = $contact['fields']['core']['email']['value'] ?? null;

        return is_string($coreValue) ? $coreValue : null;
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

            $normalized = ContactEmailNormalizer::normalize($email);
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
        $this->boundary->successfulWrite(
            $response,
            $operation,
            $this->connection,
            retryAfterSeconds: $this->retryAfterSeconds($this->segments->getResponseHeaders()),
        );
    }

    /** @param array<string, mixed> $response */
    private function mapBatch(array $response, int $expectedCount, MutationOperation $operation): ContactBatch
    {
        if (! array_key_exists('contacts', $response) && ! array_key_exists('statusCodes', $response)) {
            $this->boundary->successfulWrite($response, $operation, $this->connection);
        }

        $contacts = is_array($response['contacts'] ?? null) ? $response['contacts'] : [];
        $statusCodes = is_array($response['statusCodes'] ?? null) ? $response['statusCodes'] : [];
        $errors = is_array($response['errors'] ?? null) ? $response['errors'] : [];
        $items = [];

        for ($index = 0; $index < $expectedCount; $index++) {
            $status = $this->boundary->statusCode($statusCodes[$index] ?? null);
            if (array_key_exists($index, $errors)) {
                $error = $errors[$index];
                if (! is_array($error)) {
                    $items[] = ContactBatchItem::unknown($index, $status);

                    continue;
                }

                $errorStatus = $this->boundary->statusCode($error['code'] ?? null) ?? $status;
                $rawMessage = $error['message'] ?? null;
                if ($errorStatus === null && ! is_string($rawMessage)) {
                    $items[] = ContactBatchItem::unknown($index);

                    continue;
                }

                $message = is_string($rawMessage) ? $rawMessage : '';
                $items[] = ContactBatchItem::failed(
                    $index,
                    $this->boundary->writeFailureKind($errorStatus, $message),
                    $errorStatus,
                );

                continue;
            }

            if ($status !== null && ($status < 200 || $status >= 300)) {
                $items[] = ContactBatchItem::failed(
                    $index,
                    $this->boundary->writeFailureKind($status),
                    $status,
                );

                continue;
            }

            if ($status === null || ! array_key_exists($index, $contacts)) {
                $items[] = ContactBatchItem::unknown($index, $status);

                continue;
            }

            try {
                $contact = $this->mapper->contact($contacts[$index], $operation);
                $items[] = ContactBatchItem::successful($index, $contact, $status);
            } catch (MauticWriteException) {
                $items[] = ContactBatchItem::unknown($index, $status);
            }
        }

        return new ContactBatch($items);
    }

    private function validateRemoteId(int $remoteId): void
    {
        if ($remoteId < 1) {
            throw new InvalidWriteRequest('Remote IDs must be positive integers.');
        }
    }

    /** @param array<mixed> $headers */
    private function retryAfterSeconds(array $headers): ?int
    {
        foreach ($headers as $name => $value) {
            if (! is_string($name) || strtolower($name) !== 'retry-after') {
                continue;
            }
            if (is_array($value)) {
                $value = reset($value);
            }

            return is_string($value) && ctype_digit($value) ? (int) $value : null;
        }

        return null;
    }
}
