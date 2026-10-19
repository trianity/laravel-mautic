<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Reconciliation;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use RuntimeException;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Identity\ContactIdentityHasher;

final readonly class DatabaseReconciliationStore
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private Clock $clock,
        private ?string $databaseConnection = null,
        private string $checkpointsTable = 'mautic_reconciliation_checkpoints',
        private string $observationsTable = 'mautic_reconciliation_observations',
        private string $findingsTable = 'mautic_reconciliation_findings',
    ) {}

    public function begin(string $connection, int $pageSize, bool $restart = false): ReconciliationCheckpoint
    {
        if (trim($connection) === '' || strlen($connection) > 191) {
            throw new RuntimeException('A non-empty reconciliation connection of at most 191 bytes is required.');
        }
        if ($pageSize < 1 || $pageSize > 100) {
            throw new RuntimeException('The reconciliation page size must be between 1 and 100.');
        }
        $hash = ContactIdentityHasher::connection($connection);
        $now = $this->clock->now();
        $initial = [
            'connection_hash' => $hash,
            'connection' => $connection,
            'scan_id' => bin2hex(random_bytes(32)),
            'high_water_remote_id' => null,
            'cursor_remote_id' => 0,
            'status' => ReconciliationStatus::Running->value,
            'page_size' => $pageSize,
            'observed_count' => 0,
            'finding_count' => 0,
            'request_count' => 0,
            'next_page_at' => null,
            'last_failure_kind' => null,
            'generation' => 0,
            'reservation_owner' => null,
            'reservation_expires_at' => null,
            'started_at' => $now,
            'completed_at' => null,
            'created_at' => $this->timestamp($now),
            'updated_at' => $this->timestamp($now),
        ];
        if (! $restart) {
            $this->database()->table($this->checkpointsTable)->insertOrIgnore($initial);
            $checkpoint = $this->get($connection)
                ?? throw new RuntimeException('The reconciliation checkpoint was not persisted.');
            $this->assertConnection($checkpoint, $connection);

            return $checkpoint;
        }

        return $this->database()->transaction(function () use ($connection, $hash, $initial): ReconciliationCheckpoint {
            $row = $this->database()->table($this->checkpointsTable)
                ->where('connection_hash', $hash)
                ->lockForUpdate()
                ->first();
            if ($row === null) {
                $this->database()->table($this->checkpointsTable)->insert($initial);

                return $this->get($connection)
                    ?? throw new RuntimeException('The reconciliation checkpoint was not persisted.');
            }

            $values = $initial;
            $values['generation'] = ((int) ($row->generation ?? 0)) + 1;
            unset($values['connection_hash'], $values['created_at']);
            $this->database()->table($this->checkpointsTable)->where('connection_hash', $hash)->update($values);

            return $this->get($connection) ?? throw new RuntimeException('The reconciliation checkpoint was not persisted.');
        });
    }

    public function get(string $connection): ?ReconciliationCheckpoint
    {
        $row = $this->database()->table($this->checkpointsTable)
            ->where('connection_hash', ContactIdentityHasher::connection($connection))
            ->first();
        if ($row === null) {
            return null;
        }
        $checkpoint = $this->map($row);
        $this->assertConnection($checkpoint, $connection);

        return $checkpoint;
    }

    public function setHighWatermark(
        ReconciliationCheckpoint $expected,
        int $remoteId,
    ): ReconciliationCheckpoint {
        if ($remoteId < 0) {
            throw new RuntimeException('The reconciliation high-water mark cannot be negative.');
        }
        $now = $this->clock->now();
        $complete = $remoteId === 0;
        $updated = $this->expectedReservation($expected)
            ->whereNull('high_water_remote_id')
            ->update([
                'high_water_remote_id' => $remoteId,
                'status' => $complete ? ReconciliationStatus::Completed->value : ReconciliationStatus::Running->value,
                'next_page_at' => $complete ? null : $expected->nextPageAt,
                'completed_at' => $complete ? $now : null,
                'last_failure_kind' => null,
                'reservation_owner' => null,
                'reservation_expires_at' => null,
                'updated_at' => $this->timestamp($now),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('The reconciliation checkpoint changed concurrently.');
        }

        return $this->get($expected->connection) ?? throw new RuntimeException('The reconciliation checkpoint was lost.');
    }

    /**
     * @param  list<int>  $remoteIds
     * @param  array<int, ReconciliationFindingKind>  $findings
     */
    public function recordPage(
        ReconciliationCheckpoint $expected,
        array $remoteIds,
        array $findings,
        int $newCursor,
        bool $complete,
    ): ReconciliationCheckpoint {
        $now = $this->clock->now();

        return $this->database()->transaction(function () use (
            $expected,
            $remoteIds,
            $findings,
            $newCursor,
            $complete,
            $now,
        ): ReconciliationCheckpoint {
            $current = $this->expectedReservation($expected)->lockForUpdate()->first();
            if ($current === null) {
                throw new RuntimeException('The reconciliation checkpoint changed concurrently.');
            }

            $observed = 0;
            foreach (array_values(array_unique($remoteIds)) as $remoteId) {
                $observed += $this->database()->table($this->observationsTable)->insertOrIgnore([
                    'scan_id' => $expected->scanId,
                    'connection_hash' => ContactIdentityHasher::connection($expected->connection),
                    'remote_id' => $remoteId,
                    'observed_at' => $now,
                ]);
            }
            $findingCount = 0;
            foreach ($findings as $remoteId => $kind) {
                $findingCount += $this->database()->table($this->findingsTable)->insertOrIgnore([
                    'scan_id' => $expected->scanId,
                    'connection_hash' => ContactIdentityHasher::connection($expected->connection),
                    'remote_id' => $remoteId,
                    'kind' => $kind->value,
                    'detected_at' => $now,
                ]);
            }

            $updated = $this->expectedReservation($expected)->update([
                'cursor_remote_id' => $newCursor,
                'status' => $complete ? ReconciliationStatus::Completed->value : ReconciliationStatus::Running->value,
                'observed_count' => $expected->observedCount + $observed,
                'finding_count' => $expected->findingCount + $findingCount,
                'next_page_at' => $complete ? null : $expected->nextPageAt,
                'last_failure_kind' => null,
                'reservation_owner' => null,
                'reservation_expires_at' => null,
                'completed_at' => $complete ? $now : null,
                'updated_at' => $this->timestamp($now),
            ]);
            if ($updated !== 1) {
                throw new RuntimeException('The reconciliation request reservation changed concurrently.');
            }

            return $this->get($expected->connection) ?? throw new RuntimeException('The reconciliation checkpoint was lost.');
        });
    }

    public function fail(
        ReconciliationCheckpoint $expected,
        string $failureKind,
        ?int $retryAfterSeconds = null,
    ): ReconciliationCheckpoint {
        $now = $this->clock->now();
        $nextPageAt = max(
            $expected->nextPageAt ?? $now,
            $now + max(0, $retryAfterSeconds ?? 0),
        );
        $updated = $this->expectedReservation($expected)->update([
            'status' => ReconciliationStatus::Failed->value,
            'next_page_at' => $nextPageAt,
            'last_failure_kind' => substr($failureKind, 0, 64),
            'reservation_owner' => null,
            'reservation_expires_at' => null,
            'updated_at' => $this->timestamp($now),
        ]);
        if ($updated !== 1) {
            throw new RuntimeException('The reconciliation checkpoint changed concurrently.');
        }

        return $this->get($expected->connection) ?? throw new RuntimeException('The reconciliation checkpoint was lost.');
    }

    public function block(ReconciliationCheckpoint $expected, string $failureKind): ReconciliationCheckpoint
    {
        $now = $this->clock->now();
        $updated = $this->expectedReservation($expected)->update([
            'status' => ReconciliationStatus::Blocked->value,
            'next_page_at' => null,
            'last_failure_kind' => substr($failureKind, 0, 64),
            'reservation_owner' => null,
            'reservation_expires_at' => null,
            'updated_at' => $this->timestamp($now),
        ]);
        if ($updated !== 1) {
            throw new RuntimeException('The reconciliation request reservation changed concurrently.');
        }

        return $this->get($expected->connection) ?? throw new RuntimeException('The reconciliation checkpoint was lost.');
    }

    public function reserve(
        ReconciliationCheckpoint $expected,
        int $minimumIntervalSeconds,
        int $reservationSeconds,
    ): ?ReconciliationCheckpoint {
        if ($reservationSeconds < 1) {
            throw new RuntimeException('The reconciliation request reservation must be positive.');
        }
        $now = $this->clock->now();
        if ($expected->status === ReconciliationStatus::Completed
            || $expected->status === ReconciliationStatus::Blocked
            || ($expected->nextPageAt !== null && $expected->nextPageAt > $now)
            || ($expected->reservationOwner !== null && ($expected->reservationExpiresAt ?? PHP_INT_MAX) > $now)) {
            return null;
        }

        $owner = bin2hex(random_bytes(32));
        $query = $this->expected($expected)
            ->whereIn('status', [ReconciliationStatus::Running->value, ReconciliationStatus::Failed->value])
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('next_page_at')->orWhere('next_page_at', '<=', $now);
            })
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('reservation_owner')->orWhere('reservation_expires_at', '<=', $now);
            });
        $updated = $query->update([
            'status' => ReconciliationStatus::Running->value,
            'request_count' => $expected->requestCount + 1,
            'next_page_at' => $now + max(0, $minimumIntervalSeconds),
            'generation' => $expected->generation + 1,
            'reservation_owner' => $owner,
            'reservation_expires_at' => $now + $reservationSeconds,
            'updated_at' => $this->timestamp($now),
        ]);
        if ($updated !== 1) {
            return null;
        }

        return $this->get($expected->connection) ?? throw new RuntimeException('The reconciliation checkpoint was lost.');
    }

    public function observationCount(string $scanId): int
    {
        return $this->database()->table($this->observationsTable)->where('scan_id', $scanId)->count();
    }

    /** @return array<string, int> */
    public function findingCounts(string $connection): array
    {
        $checkpoint = $this->get($connection);
        if ($checkpoint === null) {
            return [];
        }
        $rows = $this->database()->table($this->findingsTable)
            ->where('scan_id', $checkpoint->scanId)
            ->where('connection_hash', ContactIdentityHasher::connection($connection))
            ->select('kind')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('kind')
            ->pluck('aggregate', 'kind');

        $counts = [];
        foreach ($rows as $kind => $count) {
            if (is_string($kind)) {
                $counts[$kind] = (int) $count;
            }
        }

        return $counts;
    }

    private function expected(ReconciliationCheckpoint $expected): Builder
    {
        return $this->database()->table($this->checkpointsTable)
            ->where('connection_hash', ContactIdentityHasher::connection($expected->connection))
            ->where('scan_id', $expected->scanId)
            ->where('generation', $expected->generation);
    }

    private function expectedReservation(ReconciliationCheckpoint $expected): Builder
    {
        if ($expected->reservationOwner === null) {
            throw new RuntimeException('A current reconciliation request reservation is required.');
        }

        return $this->expected($expected)
            ->where('reservation_owner', $expected->reservationOwner)
            ->where('reservation_expires_at', $expected->reservationExpiresAt);
    }

    private function database(): ConnectionInterface
    {
        return $this->connections->connection($this->databaseConnection);
    }

    private function map(object $row): ReconciliationCheckpoint
    {
        $scanId = $row->scan_id ?? null;
        $connection = $row->connection ?? null;
        $status = $row->status ?? null;
        if (! is_string($scanId) || ! is_string($connection) || ! is_string($status)) {
            throw new RuntimeException('Stored reconciliation checkpoint is invalid.');
        }

        return new ReconciliationCheckpoint(
            $scanId,
            $connection,
            is_int($row->high_water_remote_id ?? null) ? $row->high_water_remote_id : null,
            (int) ($row->cursor_remote_id ?? 0),
            ReconciliationStatus::from($status),
            (int) ($row->page_size ?? 0),
            (int) ($row->observed_count ?? 0),
            (int) ($row->finding_count ?? 0),
            (int) ($row->request_count ?? 0),
            is_int($row->next_page_at ?? null) ? $row->next_page_at : null,
            is_string($row->last_failure_kind ?? null) ? $row->last_failure_kind : null,
            (int) ($row->generation ?? 0),
            is_string($row->reservation_owner ?? null) ? $row->reservation_owner : null,
            is_int($row->reservation_expires_at ?? null) ? $row->reservation_expires_at : null,
        );
    }

    private function assertConnection(ReconciliationCheckpoint $checkpoint, string $connection): void
    {
        if ($checkpoint->connection !== $connection) {
            throw new RuntimeException('Stored reconciliation connection does not match its lookup hash.');
        }
    }

    private function timestamp(int $time): string
    {
        return date('Y-m-d H:i:s', $time);
    }
}
