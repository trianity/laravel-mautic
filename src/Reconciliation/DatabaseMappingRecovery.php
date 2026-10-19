<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Reconciliation;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use RuntimeException;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Exceptions\ContactMappingChanged;
use Trianity\LaravelMautic\Identity\ContactIdentityHasher;
use Trianity\LaravelMautic\Identity\ContactMapping;
use Trianity\LaravelMautic\Identity\RemoteContactState;

final readonly class DatabaseMappingRecovery
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private Clock $clock,
        private ?string $databaseConnection = null,
        private string $mappingsTable = 'mautic_contact_mappings',
        private string $auditsTable = 'mautic_reconciliation_audits',
    ) {}

    public function markMissing(ContactMapping $expected, string $actor, string $reasonCode): void
    {
        if (trim($actor) === '' || strlen($actor) > 64 || trim($reasonCode) === '' || strlen($reasonCode) > 64) {
            throw new RuntimeException('Mapping recovery requires bounded non-empty actor and reason codes.');
        }
        $now = $this->clock->now();
        $this->database()->transaction(function () use ($expected, $actor, $reasonCode, $now): void {
            $updated = $this->database()->table($this->mappingsTable)
                ->where('local_identity_hash', ContactIdentityHasher::local(
                    $expected->connection,
                    $expected->localReference,
                ))
                ->where('remote_identity_hash', ContactIdentityHasher::remote(
                    $expected->connection,
                    $expected->remoteId,
                ))
                ->where('generation', $expected->generation)
                ->where('remote_state', RemoteContactState::Active->value)
                ->update([
                    'remote_state' => RemoteContactState::Missing->value,
                    'merged_into_remote_id' => null,
                    'generation' => $expected->generation + 1,
                    'updated_at' => date('Y-m-d H:i:s', $now),
                ]);
            if ($updated !== 1) {
                throw new ContactMappingChanged($expected->connection);
            }
            $this->database()->table($this->auditsTable)->insert([
                'connection_hash' => ContactIdentityHasher::connection($expected->connection),
                'connection' => $expected->connection,
                'remote_id' => $expected->remoteId,
                'action' => 'mark_remote_missing',
                'actor' => $actor,
                'reason_code' => $reasonCode,
                'created_at' => $now,
            ]);
        });
    }

    private function database(): ConnectionInterface
    {
        return $this->connections->connection($this->databaseConnection);
    }
}
