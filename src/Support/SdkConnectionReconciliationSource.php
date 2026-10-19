<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Support;

use Mautic\Api\Contacts;
use Mautic\QueryBuilder\QueryBuilder;
use Trianity\LaravelMautic\Read\ReadOperation;
use Trianity\LaravelMautic\Reconciliation\ReconciliationPage;

final readonly class SdkConnectionReconciliationSource
{
    private ContactResponseMapper $mapper;

    public function __construct(
        private Contacts $contacts,
        private string $connection,
        private SdkResponseBoundary $boundary,
    ) {
        $this->mapper = new ContactResponseMapper($connection);
    }

    public function highWatermark(): int
    {
        $raw = $this->contacts->getList('', 0, 1, 'id', 'DESC');
        if (! is_array($raw)) {
            $this->boundary->unexpected(ReadOperation::SearchContacts, $this->connection);
        }
        $response = $this->boundary->successful($raw, ReadOperation::SearchContacts, $this->connection);
        if (! is_int($response['total'] ?? null) || ! is_array($response['contacts'] ?? null)) {
            $this->boundary->unexpected(ReadOperation::SearchContacts, $this->connection);
        }
        if ($response['contacts'] === []) {
            return 0;
        }

        return $this->mapper->contact(reset($response['contacts']), ReadOperation::SearchContacts)->remoteId;
    }

    public function page(int $fromRemoteId, int $throughRemoteId, int $limit): ReconciliationPage
    {
        $query = new QueryBuilder;
        $query->addWhere($query->expr()->gt('id', $fromRemoteId)->lte('id', $throughRemoteId));
        $query->addOrder('id', 'asc');
        $raw = $this->contacts->getCustomList($query, 0, $limit);
        if (! is_array($raw)) {
            $this->boundary->unexpected(ReadOperation::SearchContacts, $this->connection);
        }
        $response = $this->boundary->successful($raw, ReadOperation::SearchContacts, $this->connection);
        if (! is_int($response['total'] ?? null) || ! is_array($response['contacts'] ?? null)) {
            $this->boundary->unexpected(ReadOperation::SearchContacts, $this->connection);
        }

        $mapped = [];
        foreach ($response['contacts'] as $payload) {
            $mapped[] = $this->mapper->contact($payload, ReadOperation::SearchContacts);
        }

        return new ReconciliationPage($mapped);
    }
}
