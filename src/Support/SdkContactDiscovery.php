<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Support;

use Mautic\Api\Contacts;
use Mautic\QueryBuilder\QueryBuilder;
use Trianity\LaravelMautic\Contracts\ContactDiscovery;
use Trianity\LaravelMautic\Data\ValuePresence;
use Trianity\LaravelMautic\Exceptions\InvalidReadRequest;
use Trianity\LaravelMautic\Identity\ContactDiscoveryResult;
use Trianity\LaravelMautic\Read\ReadOperation;

final readonly class SdkContactDiscovery implements ContactDiscovery
{
    private ContactResponseMapper $mapper;

    public function __construct(
        private Contacts $contacts,
        private string $connection,
        private SdkResponseBoundary $boundary,
    ) {
        $this->mapper = new ContactResponseMapper($connection);
    }

    public function exactEmail(string $email): ContactDiscoveryResult
    {
        $normalizedEmail = ContactEmailNormalizer::normalize($email);
        if ($normalizedEmail === '') {
            throw new InvalidReadRequest('A non-empty email is required for contact discovery.');
        }

        $query = new QueryBuilder;
        $query->addWhere($query->expr()->eq('email', $normalizedEmail));
        $offset = 0;
        $matches = [];

        do {
            $rawResponse = $this->contacts->getCustomList($query, $offset, 100);
            if (! is_array($rawResponse)) {
                $this->boundary->unexpected(ReadOperation::SearchContacts, $this->connection);
            }
            $response = $this->boundary->successful(
                $rawResponse,
                ReadOperation::SearchContacts,
                $this->connection,
            );
            if (! is_int($response['total'] ?? null) || ! is_array($response['contacts'] ?? null)) {
                $this->boundary->unexpected(ReadOperation::SearchContacts, $this->connection);
            }

            foreach ($response['contacts'] as $payload) {
                $contact = $this->mapper->contact($payload, ReadOperation::SearchContacts);
                $remoteEmail = $contact->field('email');
                if ($remoteEmail->presence !== ValuePresence::Value
                    || ! is_string($remoteEmail->value())) {
                    $this->boundary->unexpected(ReadOperation::SearchContacts, $this->connection);
                }
                if (ContactEmailNormalizer::normalize($remoteEmail->value()) === $normalizedEmail) {
                    $matches[$contact->remoteId] = $contact;
                }
            }

            $count = count($response['contacts']);
            $offset += $count;
            if ($count === 0 && $offset < $response['total']) {
                $this->boundary->unexpected(ReadOperation::SearchContacts, $this->connection);
            }
        } while ($offset < $response['total']);

        return new ContactDiscoveryResult($normalizedEmail, array_values($matches));
    }
}
