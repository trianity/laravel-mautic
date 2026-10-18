<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Fakes;

use Trianity\LaravelMautic\Contracts\ContactWriter;
use Trianity\LaravelMautic\Data\Contact;
use Trianity\LaravelMautic\Data\ContactBatch;
use Trianity\LaravelMautic\Data\OptionalValue;
use Trianity\LaravelMautic\Data\ValuePresence;
use Trianity\LaravelMautic\Exceptions\ContactAlreadyExists;
use Trianity\LaravelMautic\Exceptions\ContactUpdateTargetNotFound;
use Trianity\LaravelMautic\Exceptions\InvalidWriteRequest;
use Trianity\LaravelMautic\Write\ContactFields;
use Trianity\LaravelMautic\Write\ContactUpdate;
use Trianity\LaravelMautic\Write\MutationOperation;

final class FakeContactWriter implements ContactWriter
{
    /** @var array<int, Contact> */
    private array $storedContacts = [];

    /** @var array<int, list<int>> */
    private array $segmentIds = [];

    private function __construct(private int $nextRemoteId) {}

    public static function make(int $startingRemoteId = 1): self
    {
        if ($startingRemoteId < 1) {
            throw new InvalidWriteRequest('The starting remote ID must be positive.');
        }

        return new self($startingRemoteId);
    }

    public function withContacts(Contact ...$contacts): self
    {
        foreach ($contacts as $contact) {
            $this->storedContacts[$contact->remoteId] = $contact;
            $this->nextRemoteId = max($this->nextRemoteId, $contact->remoteId + 1);
        }

        return $this;
    }

    public function create(ContactFields $fields): Contact
    {
        $this->rejectKnownDuplicate($fields, MutationOperation::CreateContact);
        $contact = new Contact($this->nextRemoteId++, $this->mappedFields($fields));
        $this->storedContacts[$contact->remoteId] = $contact;

        return $contact;
    }

    public function update(int $remoteId, ContactFields $fields): Contact
    {
        $contact = $this->storedContacts[$remoteId] ?? throw new ContactUpdateTargetNotFound('fake');
        $storedFields = $contact->fields->presence === ValuePresence::Value
            ? $contact->fields->value()
            : null;
        $values = is_array($storedFields) ? $storedFields : [];

        foreach ($fields->toPayload() as $alias => $value) {
            $values[$alias] = OptionalValue::present($value);
        }

        return $this->storedContacts[$remoteId] = new Contact(
            $remoteId,
            OptionalValue::present($values),
            $contact->tags,
            $contact->doNotContact,
        );
    }

    public function createBatch(ContactFields ...$contacts): ContactBatch
    {
        if ($contacts === []) {
            throw new InvalidWriteRequest('A contact creation batch cannot be empty.');
        }

        $seen = [];
        foreach ($contacts as $fields) {
            $email = $fields->value('email');
            if ($fields->has('email') && is_string($email) && trim($email) !== '') {
                $normalized = strtolower(trim($email));
                if (isset($seen[$normalized])) {
                    throw new ContactAlreadyExists('fake', MutationOperation::CreateContactsBatch);
                }
                $seen[$normalized] = true;
            }

            $this->rejectKnownDuplicate($fields, MutationOperation::CreateContactsBatch);
        }

        return new ContactBatch(array_values(array_map(
            fn (ContactFields $fields): Contact => $this->create($fields),
            $contacts,
        )));
    }

    public function updateBatch(ContactUpdate ...$contacts): ContactBatch
    {
        if ($contacts === []) {
            throw new InvalidWriteRequest('A contact update batch cannot be empty.');
        }

        foreach ($contacts as $update) {
            if (! isset($this->storedContacts[$update->remoteId])) {
                throw new ContactUpdateTargetNotFound('fake', MutationOperation::UpdateContactsBatch);
            }
        }

        return new ContactBatch(array_values(array_map(
            fn (ContactUpdate $update): Contact => $this->update($update->remoteId, $update->fields),
            $contacts,
        )));
    }

    public function addToSegment(int $remoteId, int $segmentId): void
    {
        $this->requireContact($remoteId, MutationOperation::AddContactToSegment);
        $ids = $this->segmentIds[$remoteId] ?? [];
        if (! in_array($segmentId, $ids, true)) {
            $ids[] = $segmentId;
        }
        $this->segmentIds[$remoteId] = $ids;
    }

    public function removeFromSegment(int $remoteId, int $segmentId): void
    {
        $this->requireContact($remoteId, MutationOperation::RemoveContactFromSegment);
        $this->segmentIds[$remoteId] = array_values(array_filter(
            $this->segmentIds[$remoteId] ?? [],
            static fn (int $id): bool => $id !== $segmentId,
        ));
    }

    /** @return list<Contact> */
    public function contacts(): array
    {
        return array_values($this->storedContacts);
    }

    /** @return list<int> */
    public function segmentIdsFor(int $remoteId): array
    {
        return $this->segmentIds[$remoteId] ?? [];
    }

    /** @return OptionalValue<array<string, OptionalValue<mixed>>> */
    private function mappedFields(ContactFields $fields): OptionalValue
    {
        $values = [];
        foreach ($fields->toPayload() as $alias => $value) {
            $values[$alias] = OptionalValue::present($value);
        }

        return OptionalValue::present($values);
    }

    private function rejectKnownDuplicate(ContactFields $fields, MutationOperation $operation): void
    {
        if (! $fields->has('email') || ! is_string($fields->value('email'))) {
            return;
        }

        $email = strtolower(trim($fields->value('email')));
        if ($email === '') {
            return;
        }

        foreach ($this->storedContacts as $contact) {
            $existing = $contact->field('email');
            if ($existing->presence === ValuePresence::Value
                && is_string($existing->value())
                && strtolower(trim($existing->value())) === $email) {
                throw new ContactAlreadyExists('fake', $operation);
            }
        }
    }

    private function requireContact(int $remoteId, MutationOperation $operation): void
    {
        if (! isset($this->storedContacts[$remoteId])) {
            throw new ContactUpdateTargetNotFound('fake', $operation);
        }
    }
}
