<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Contracts;

use Trianity\LaravelMautic\Reconciliation\ReconciliationPage;

interface ReconciliationSource
{
    public function highWatermark(string $connection): int;

    public function page(
        string $connection,
        int $fromRemoteId,
        int $throughRemoteId,
        int $limit,
    ): ReconciliationPage;
}
