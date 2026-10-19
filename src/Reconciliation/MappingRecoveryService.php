<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Reconciliation;

use RuntimeException;
use Trianity\LaravelMautic\Contracts\ContactReaderProvider;
use Trianity\LaravelMautic\Exceptions\ContactNotFound;

final readonly class MappingRecoveryService
{
    public function __construct(
        private DatabaseMappingInventory $inventory,
        private ContactReaderProvider $readers,
        private DatabaseMappingRecovery $recovery,
    ) {}

    public function run(
        string $connection,
        int $afterRemoteId = 0,
        int $limit = 50,
        bool $applyMissing = false,
        ?string $actor = null,
        ?string $reasonCode = null,
    ): MappingRecoveryReport {
        if ($applyMissing && ($actor === null || $reasonCode === null)) {
            throw new RuntimeException('Applying missing mappings requires an actor and reason code.');
        }

        $page = $this->inventory->activePage($connection, $afterRemoteId, $limit);
        $reader = $this->readers->forConnection($connection);
        $missing = [];
        $applied = 0;
        foreach ($page->mappings as $mapping) {
            try {
                $reader->find($mapping->remoteId);
            } catch (ContactNotFound) {
                $missing[] = $mapping->remoteId;
                if ($applyMissing) {
                    $this->recovery->markMissing($mapping, $actor, $reasonCode);
                    $applied++;
                }
            }
        }

        return new MappingRecoveryReport(
            $connection,
            ! $applyMissing,
            count($page->mappings),
            $missing,
            $applied,
            $page->nextRemoteId,
            $page->complete,
        );
    }
}
