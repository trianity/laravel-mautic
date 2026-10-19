<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Reconciliation;

final readonly class ConnectionDiagnostics
{
    /**
     * @param  array<string, int>  $mappingStates
     * @param  array<string, int>  $outboxStates
     * @param  array<string, int>  $inboxStates
     * @param  array<string, int>  $reconciliationFindings
     */
    public function __construct(
        public string $connection,
        public array $mappingStates,
        public array $outboxStates,
        public array $inboxStates,
        public array $reconciliationFindings,
        public OAuthTokenHealth $tokenHealth,
        public ?ReconciliationCheckpoint $checkpoint,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'connection' => $this->connection,
            'mapping_states' => $this->mappingStates,
            'outbox_states' => $this->outboxStates,
            'inbox_states' => $this->inboxStates,
            'reconciliation_findings' => $this->reconciliationFindings,
            'token_health' => $this->tokenHealth->value,
            'checkpoint' => $this->checkpoint === null ? null : [
                'scan_id' => $this->checkpoint->scanId,
                'status' => $this->checkpoint->status->value,
                'high_water_remote_id' => $this->checkpoint->highWaterRemoteId,
                'cursor_remote_id' => $this->checkpoint->cursorRemoteId,
                'observed_count' => $this->checkpoint->observedCount,
                'finding_count' => $this->checkpoint->findingCount,
                'request_count' => $this->checkpoint->requestCount,
                'next_page_at' => $this->checkpoint->nextPageAt,
                'last_failure_kind' => $this->checkpoint->lastFailureKind,
            ],
        ];
    }
}
