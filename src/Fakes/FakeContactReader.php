<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Fakes;

use Trianity\LaravelMautic\Contracts\ContactReader;
use Trianity\LaravelMautic\Data\Contact;
use Trianity\LaravelMautic\Data\ContactPage;
use Trianity\LaravelMautic\Data\SegmentMembership;
use Trianity\LaravelMautic\Data\SegmentMemberships;
use Trianity\LaravelMautic\Exceptions\ContactNotFound;
use Trianity\LaravelMautic\Exceptions\MauticReadException;
use Trianity\LaravelMautic\Read\ContactSearch;
use Trianity\LaravelMautic\Read\ReadFailureKind;
use Trianity\LaravelMautic\Read\ReadOperation;

final class FakeContactReader implements ContactReader
{
    /** @var array<int, Contact> */
    private array $contacts = [];

    /** @var array<string, list<Contact>> */
    private array $searches = [];

    /** @var array<int, list<SegmentMembership>> */
    private array $memberships = [];

    private ?ReadFailureKind $nextFailure = null;

    private function __construct() {}

    public static function make(): self
    {
        return new self;
    }

    public function withContacts(Contact ...$contacts): self
    {
        foreach ($contacts as $contact) {
            $this->contacts[$contact->remoteId] = $contact;
        }

        return $this;
    }

    public function withSearchResult(string $query, Contact ...$contacts): self
    {
        $this->searches[$query] = array_values($contacts);

        return $this;
    }

    public function withSegmentMemberships(int $contactId, SegmentMembership ...$memberships): self
    {
        $this->memberships[$contactId] = array_values($memberships);

        return $this;
    }

    public function failNext(ReadFailureKind $kind): self
    {
        $this->nextFailure = $kind;

        return $this;
    }

    public function find(int $remoteId): Contact
    {
        $this->throwNextFailure(ReadOperation::FindContact);

        return $this->contacts[$remoteId] ?? throw new ContactNotFound('fake');
    }

    public function search(ContactSearch $search): ContactPage
    {
        $this->throwNextFailure(ReadOperation::SearchContacts);
        $contacts = $this->searches[$search->query] ?? [];

        return new ContactPage(
            array_slice($contacts, $search->offset, $search->limit),
            count($contacts),
            $search->offset,
            $search->limit,
        );
    }

    public function segments(int $remoteId): SegmentMemberships
    {
        $this->throwNextFailure(ReadOperation::ListContactSegments);

        if (! isset($this->contacts[$remoteId])) {
            throw new ContactNotFound('fake', ReadOperation::ListContactSegments);
        }

        return new SegmentMemberships($remoteId, $this->memberships[$remoteId] ?? []);
    }

    private function throwNextFailure(ReadOperation $operation): void
    {
        if ($this->nextFailure === null) {
            return;
        }

        $kind = $this->nextFailure;
        $this->nextFailure = null;

        if ($kind === ReadFailureKind::NotFound
            && in_array($operation, [ReadOperation::FindContact, ReadOperation::ListContactSegments], true)) {
            throw new ContactNotFound('fake', $operation);
        }

        throw new MauticReadException($kind, $operation, 'fake');
    }
}
