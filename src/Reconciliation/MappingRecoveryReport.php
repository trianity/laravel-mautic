<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Reconciliation;

final readonly class MappingRecoveryReport
{
    /** @param list<int> $missingRemoteIds */
    public function __construct(
        public string $connection,
        public bool $dryRun,
        public int $checked,
        public array $missingRemoteIds,
        public int $applied,
        public int $nextRemoteId,
        public bool $complete,
    ) {}
}
