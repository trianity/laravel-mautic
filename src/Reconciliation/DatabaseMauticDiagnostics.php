<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Reconciliation;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Identity\ContactIdentityHasher;

final readonly class DatabaseMauticDiagnostics
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private Repository $config,
        private Clock $clock,
        private DatabaseReconciliationStore $reconciliation,
    ) {}

    public function forConnection(string $connection): ConnectionDiagnostics
    {
        return new ConnectionDiagnostics(
            $connection,
            $this->mappingStates($connection),
            $this->outboxStates($connection),
            $this->inboxStates($connection),
            $this->reconciliationFindings($connection),
            $this->tokenHealth($connection),
            $this->checkpoint($connection),
        );
    }

    /** @return array<string, int> */
    private function mappingStates(string $connection): array
    {
        $database = $this->database('identity');
        $table = $this->table('identity', 'mappings_table', 'mautic_contact_mappings');
        if (! $this->hasTable($database, $table)) {
            return [];
        }
        $rows = $database->table($table)->where('connection', $connection)->get(['connection', 'remote_state']);
        $counts = [];
        foreach ($rows as $row) {
            if (! is_string($row->connection ?? null) || ! hash_equals($connection, $row->connection)
                || ! is_string($row->remote_state ?? null)) {
                continue;
            }
            $counts[$row->remote_state] = ($counts[$row->remote_state] ?? 0) + 1;
        }

        return $counts;
    }

    /** @return array<string, int> */
    private function outboxStates(string $connection): array
    {
        $database = $this->database('outbox');
        $table = $this->table('outbox', 'commands_table', 'mautic_outbox_commands');
        if (! $this->hasTable($database, $table)) {
            return [];
        }

        return $this->groupedCounts($database->table($table)
            ->where('connection_hash', ContactIdentityHasher::connection($connection))
            ->where('connection', $connection), 'status');
    }

    /** @return array<string, int> */
    private function inboxStates(string $connection): array
    {
        $database = $this->database('inbox');
        $events = $this->table('inbox', 'events_table', 'mautic_webhook_events');
        $deliveries = $this->table('inbox', 'deliveries_table', 'mautic_webhook_deliveries');
        if (! $this->hasTable($database, $events)
            || ! $this->hasTable($database, $deliveries)) {
            return [];
        }

        return $this->groupedCounts($database->table($events.' as events')
            ->join($deliveries.' as deliveries', 'deliveries.id', '=', 'events.delivery_id')
            ->where('deliveries.connection_hash', ContactIdentityHasher::connection($connection))
            ->where('deliveries.connection', $connection), 'events.status');
    }

    /** @return array<string, int> */
    private function reconciliationFindings(string $connection): array
    {
        $database = $this->database('reconciliation');
        $table = $this->table('reconciliation', 'findings_table', 'mautic_reconciliation_findings');

        return $this->hasTable($database, $table)
            ? $this->reconciliation->findingCounts($connection)
            : [];
    }

    private function tokenHealth(string $connection): OAuthTokenHealth
    {
        $values = $this->config->get('mautic.connections.'.$connection);
        if (! is_array($values) || ($values['auth'] ?? 'basic') !== 'oauth2') {
            return OAuthTokenHealth::NotApplicable;
        }
        $database = $this->database('oauth');
        $table = $this->table('oauth', 'tokens_table', 'mautic_oauth_tokens');
        if (! $this->hasTable($database, $table)) {
            return OAuthTokenHealth::StorageUnavailable;
        }
        $row = $database->table($table)->where('connection', $connection)->first(['connection', 'expires_at']);
        if ($row === null || ! is_string($row->connection ?? null) || ! hash_equals($connection, $row->connection)
            || ! is_int($row->expires_at ?? null)) {
            return OAuthTokenHealth::Missing;
        }
        if ($row->expires_at <= $this->clock->now()) {
            return OAuthTokenHealth::Expired;
        }
        $leeway = max(0, (int) $this->config->get('mautic.oauth.expiry_leeway_seconds', 60));

        return $row->expires_at <= $this->clock->now() + $leeway
            ? OAuthTokenHealth::Expiring
            : OAuthTokenHealth::Valid;
    }

    private function checkpoint(string $connection): ?ReconciliationCheckpoint
    {
        $database = $this->database('reconciliation');
        $table = $this->table('reconciliation', 'checkpoints_table', 'mautic_reconciliation_checkpoints');

        return $this->hasTable($database, $table) ? $this->reconciliation->get($connection) : null;
    }

    /** @return array<string, int> */
    private function groupedCounts(Builder $query, string $column): array
    {
        $rows = $query->select($column)->selectRaw('COUNT(*) as aggregate')->groupBy($column)->get();
        $counts = [];
        $property = str_contains($column, '.') ? substr($column, (int) strrpos($column, '.') + 1) : $column;
        foreach ($rows as $row) {
            $value = $row->{$property} ?? null;
            if (is_string($value)) {
                $counts[$value] = (int) ($row->aggregate ?? 0);
            }
        }

        return $counts;
    }

    private function database(string $section): ConnectionInterface
    {
        $name = $this->config->get('mautic.'.$section.'.database_connection');

        return $this->connections->connection(is_string($name) && $name !== '' ? $name : null);
    }

    private function hasTable(ConnectionInterface $database, string $table): bool
    {
        return $database instanceof Connection && $database->getSchemaBuilder()->hasTable($table);
    }

    private function table(string $section, string $key, string $default): string
    {
        $table = $this->config->get('mautic.'.$section.'.'.$key, $default);

        return is_string($table) && $table !== '' ? $table : $default;
    }
}
