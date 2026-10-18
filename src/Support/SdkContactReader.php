<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Support;

use Mautic\Api\Contacts;
use Trianity\LaravelMautic\Contracts\ContactReader;
use Trianity\LaravelMautic\Data\Contact;
use Trianity\LaravelMautic\Data\ContactPage;
use Trianity\LaravelMautic\Data\SegmentMemberships;
use Trianity\LaravelMautic\Read\ContactSearch;
use Trianity\LaravelMautic\Read\ReadOperation;

final readonly class SdkContactReader implements ContactReader
{
    private ContactResponseMapper $mapper;

    public function __construct(
        private Contacts $contacts,
        private string $connection,
        private SdkResponseBoundary $boundary,
    ) {
        $this->mapper = new ContactResponseMapper($connection);
    }

    public function find(int $remoteId): Contact
    {
        $operation = ReadOperation::FindContact;
        $response = $this->boundary->successful($this->contacts->get($remoteId), $operation, $this->connection);

        if (! array_key_exists('contact', $response)) {
            $this->boundary->unexpected($operation, $this->connection);
        }

        return $this->mapper->contact($response['contact'], $operation);
    }

    public function search(ContactSearch $search): ContactPage
    {
        $operation = ReadOperation::SearchContacts;
        $response = $this->boundary->successful(
            $this->contacts->getList($search->query, $search->offset, $search->limit),
            $operation,
            $this->connection,
        );

        if (! is_int($response['total'] ?? null) || ! is_array($response['contacts'] ?? null)) {
            $this->boundary->unexpected($operation, $this->connection);
        }

        $contacts = [];
        foreach ($response['contacts'] as $contact) {
            $contacts[] = $this->mapper->contact($contact, $operation);
        }

        return new ContactPage($contacts, $response['total'], $search->offset, $search->limit);
    }

    public function segments(int $remoteId): SegmentMemberships
    {
        $operation = ReadOperation::ListContactSegments;
        $response = $this->boundary->successful(
            $this->contacts->getContactSegments($remoteId),
            $operation,
            $this->connection,
        );

        if (! is_int($response['total'] ?? null) || ! is_array($response['lists'] ?? null)) {
            $this->boundary->unexpected($operation, $this->connection);
        }

        $memberships = $this->mapper->segments($response['lists'], $operation);
        if (count($memberships) !== $response['total']) {
            $this->boundary->unexpected($operation, $this->connection);
        }

        return new SegmentMemberships($remoteId, $memberships);
    }
}
