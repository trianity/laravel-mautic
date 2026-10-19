<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Reconciliation;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use RuntimeException;
use Trianity\LaravelMautic\Contracts\ContactMappingRepository;
use Trianity\LaravelMautic\Identity\ContactIdentityHasher;
use Trianity\LaravelMautic\Identity\RemoteContactState;

final readonly class DatabaseMappingInventory
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ContactMappingRepository $mappings,
        private ?string $databaseConnection = null,
        private string $table = 'mautic_contact_mappings',
    ) {}

    public function activePage(string $connection, int $afterRemoteId, int $limit): MappingInventoryPage
    {
        if ($afterRemoteId < 0 || $limit < 1 || $limit > 100) {
            throw new RuntimeException('Mapping recovery requires a non-negative cursor and a limit from 1 to 100.');
        }
        $rows = $this->database()->table($this->table)
            ->where('connection_hash', ContactIdentityHasher::connection($connection))
            ->where('connection', $connection)
            ->where('remote_state', RemoteContactState::Active->value)
            ->where('remote_id', '>', $afterRemoteId)
            ->orderBy('remote_id')
            ->limit($limit + 1)
            ->pluck('remote_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $complete = count($rows) <= $limit;
        $rows = array_slice($rows, 0, $limit);

        $found = [];
        foreach ($rows as $remoteId) {
            $mapping = $this->mappings->findByRemoteId($connection, $remoteId);
            if ($mapping !== null && $mapping->remoteState === RemoteContactState::Active) {
                $found[] = $mapping;
            }
        }

        return new MappingInventoryPage(
            $found,
            $rows === [] ? $afterRemoteId : max($rows),
            $complete,
        );
    }

    private function database(): ConnectionInterface
    {
        return $this->connections->connection($this->databaseConnection);
    }
}
