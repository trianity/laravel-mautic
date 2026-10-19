<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Support;

use Trianity\LaravelMautic\Contracts\ReconciliationSource;
use Trianity\LaravelMautic\MauticManager;
use Trianity\LaravelMautic\Reconciliation\ReconciliationPage;

final readonly class ManagerReconciliationSource implements ReconciliationSource
{
    public function __construct(private MauticManager $manager) {}

    public function highWatermark(string $connection): int
    {
        return $this->manager->connection($connection)->reconciliationReads()->highWatermark();
    }

    public function page(
        string $connection,
        int $fromRemoteId,
        int $throughRemoteId,
        int $limit,
    ): ReconciliationPage {
        return $this->manager->connection($connection)
            ->reconciliationReads()
            ->page($fromRemoteId, $throughRemoteId, $limit);
    }
}
