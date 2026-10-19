<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Inbox;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Encryption\Encrypter;
use RuntimeException;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Identity\ContactIdentityHasher;

final readonly class DatabaseWebhookInboxStore
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private Encrypter $encrypter,
        private Clock $clock,
        private ?string $databaseConnection = null,
        private string $deliveriesTable = 'mautic_webhook_deliveries',
        private string $eventsTable = 'mautic_webhook_events',
        private string $locksTable = 'mautic_webhook_locks',
    ) {}

    /** @param list<ParsedWebhookEvent> $events */
    public function record(string $receiptId, string $connection, string $rawBody, array $events): void
    {
        $now = $this->clock->now();
        $this->database()->transaction(function () use ($receiptId, $connection, $rawBody, $events, $now): void {
            $deliveryId = $this->database()->table($this->deliveriesTable)->insertGetId([
                'receipt_id' => $receiptId,
                'connection' => $connection,
                'connection_hash' => ContactIdentityHasher::connection($connection),
                'body_hash' => hash('sha256', $rawBody),
                'event_count' => count($events),
                'received_at' => $now,
                'created_at' => date('Y-m-d H:i:s', $now),
                'updated_at' => date('Y-m-d H:i:s', $now),
            ]);

            foreach ($events as $event) {
                $json = json_encode($event->payload, JSON_THROW_ON_ERROR);
                $this->database()->table($this->eventsTable)->insert([
                    'delivery_id' => $deliveryId,
                    'ordinal' => $event->ordinal,
                    'event_type' => $event->type,
                    'semantic_hash' => $event->semanticId === null
                        ? null
                        : $this->semanticHash($event->type, $event->semanticId),
                    'remote_id' => $event->remoteId,
                    'occurred_at' => $event->occurredAt,
                    'payload' => $this->encrypter->encryptString($json),
                    'payload_hash' => hash('sha256', $json),
                    'status' => WebhookEventStatus::Pending->value,
                    'attempts' => 0,
                    'created_at' => date('Y-m-d H:i:s', $now),
                    'updated_at' => date('Y-m-d H:i:s', $now),
                ]);
            }
        });
    }

    /** @return list<int> */
    public function eventIdsForReceipt(string $receiptId): array
    {
        return array_values($this->database()->table($this->eventsTable.' as events')
            ->join($this->deliveriesTable.' as deliveries', 'deliveries.id', '=', 'events.delivery_id')
            ->where('deliveries.receipt_id', $receiptId)
            ->orderBy('events.ordinal')
            ->pluck('events.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all());
    }

    /** @return list<int> */
    public function recoverableEventIds(int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        return array_values($this->database()->table($this->eventsTable)
            ->where(function ($query): void {
                $query->where('status', WebhookEventStatus::Pending->value)
                    ->orWhere(function ($expired): void {
                        $expired->where('status', WebhookEventStatus::Processing->value)
                            ->where('lease_expires_at', '<=', $this->clock->now());
                    });
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all());
    }

    public function acquire(int $eventId, string $owner, int $leaseSeconds): ?StoredWebhookEvent
    {
        if ($leaseSeconds < 1 || $owner === '') {
            throw new RuntimeException('A positive inbox lease and non-empty owner are required.');
        }

        $now = $this->clock->now();

        return $this->database()->transaction(function () use ($eventId, $owner, $leaseSeconds, $now): ?StoredWebhookEvent {
            $row = $this->database()->table($this->eventsTable)->where('id', $eventId)->lockForUpdate()->first();
            if ($row === null || ! is_string($row->status ?? null)) {
                return null;
            }
            $status = WebhookEventStatus::tryFrom($row->status);
            $expired = $status === WebhookEventStatus::Processing
                && is_int($row->lease_expires_at ?? null)
                && $row->lease_expires_at <= $now;
            if (! in_array($status, [WebhookEventStatus::Pending, WebhookEventStatus::Failed], true) && ! $expired) {
                return null;
            }

            $this->database()->table($this->eventsTable)->where('id', $eventId)->update([
                'status' => WebhookEventStatus::Processing->value,
                'attempts' => ((int) ($row->attempts ?? 0)) + 1,
                'lease_owner' => $owner,
                'lease_expires_at' => $now + $leaseSeconds,
                'last_error_code' => null,
                'updated_at' => date('Y-m-d H:i:s', $now),
            ]);

            return $this->read($eventId);
        });
    }

    public function finish(int $eventId, string $owner, WebhookEventStatus $status, ?string $reason = null): void
    {
        if (! in_array($status, [
            WebhookEventStatus::Processed,
            WebhookEventStatus::NoChange,
            WebhookEventStatus::Ignored,
            WebhookEventStatus::Duplicate,
            WebhookEventStatus::Stale,
            WebhookEventStatus::Failed,
        ], true)) {
            throw new RuntimeException('The inbox event cannot finish in that status.');
        }

        $now = $this->clock->now();
        $updated = $this->database()->table($this->eventsTable)
            ->where('id', $eventId)
            ->where('status', WebhookEventStatus::Processing->value)
            ->where('lease_owner', $owner)
            ->update([
                'status' => $status->value,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'last_error_code' => $reason,
                'processed_at' => $status === WebhookEventStatus::Failed ? null : $now,
                'updated_at' => date('Y-m-d H:i:s', $now),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('The inbox event lease is no longer owned.');
        }
    }

    public function defer(int $eventId, string $owner): void
    {
        $now = $this->clock->now();
        $updated = $this->database()->table($this->eventsTable)
            ->where('id', $eventId)
            ->where('status', WebhookEventStatus::Processing->value)
            ->where('lease_owner', $owner)
            ->update([
                'status' => WebhookEventStatus::Pending->value,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'updated_at' => date('Y-m-d H:i:s', $now),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('The inbox event lease is no longer owned.');
        }
    }

    public function acquireContact(string $connection, int $remoteId, string $owner, int $leaseSeconds): bool
    {
        $contactHash = ContactIdentityHasher::remote($connection, $remoteId);
        $now = $this->clock->now();

        return $this->database()->transaction(function () use ($contactHash, $owner, $leaseSeconds, $now): bool {
            $this->database()->table($this->locksTable)
                ->where('contact_hash', $contactHash)
                ->where('expires_at', '<=', $now)
                ->delete();
            $this->database()->table($this->locksTable)->insertOrIgnore([
                'contact_hash' => $contactHash,
                'owner' => $owner,
                'expires_at' => $now + $leaseSeconds,
            ]);

            $lock = $this->database()->table($this->locksTable)->where('contact_hash', $contactHash)->first();
            if ($lock === null || ! is_string($lock->owner ?? null)) {
                return false;
            }

            return hash_equals($owner, $lock->owner);
        });
    }

    public function releaseContact(string $connection, int $remoteId, string $owner): void
    {
        $this->database()->table($this->locksTable)
            ->where('contact_hash', ContactIdentityHasher::remote($connection, $remoteId))
            ->where('owner', $owner)
            ->delete();
    }

    public function hasProcessedSemanticDuplicate(StoredWebhookEvent $event): bool
    {
        if ($event->semanticHash === null) {
            return false;
        }

        return $this->terminalQuery($event)
            ->where('events.id', '!=', $event->id)
            ->where('events.event_type', $event->eventType)
            ->where('events.semantic_hash', $event->semanticHash)
            ->exists();
    }

    public function hasProcessedNewerEvent(StoredWebhookEvent $event): bool
    {
        if ($event->remoteId === null || $event->occurredAt === null) {
            return false;
        }

        return $this->terminalQuery($event)
            ->where('events.id', '!=', $event->id)
            ->where('events.remote_id', $event->remoteId)
            ->where('events.occurred_at', '>', $event->occurredAt)
            ->exists();
    }

    public function status(int $eventId): WebhookEventStatus
    {
        $status = $this->database()->table($this->eventsTable)->where('id', $eventId)->value('status');

        return is_string($status) ? WebhookEventStatus::from($status) : throw new RuntimeException('Inbox event not found.');
    }

    private function read(int $eventId): StoredWebhookEvent
    {
        $row = $this->database()->table($this->eventsTable.' as events')
            ->join($this->deliveriesTable.' as deliveries', 'deliveries.id', '=', 'events.delivery_id')
            ->where('events.id', $eventId)
            ->select([
                'events.*',
                'deliveries.receipt_id',
                'deliveries.connection',
            ])
            ->first();
        if ($row === null || ! is_string($row->payload ?? null)) {
            throw new RuntimeException('Stored inbox event is invalid.');
        }
        $json = $this->encrypter->decryptString($row->payload);
        $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($payload) || array_is_list($payload)) {
            throw new RuntimeException('Stored inbox payload is invalid.');
        }

        return new StoredWebhookEvent(
            (int) $row->id,
            (string) $row->receipt_id,
            (string) $row->connection,
            (string) $row->event_type,
            is_string($row->semantic_hash ?? null) ? $row->semantic_hash : null,
            is_int($row->remote_id ?? null) ? $row->remote_id : null,
            is_int($row->occurred_at ?? null) ? $row->occurred_at : null,
            $payload,
            (int) $row->attempts,
        );
    }

    private function terminalQuery(StoredWebhookEvent $event): Builder
    {
        return $this->database()->table($this->eventsTable.' as events')
            ->join($this->deliveriesTable.' as deliveries', 'deliveries.id', '=', 'events.delivery_id')
            ->where('deliveries.connection_hash', ContactIdentityHasher::connection($event->connection))
            ->where('deliveries.connection', $event->connection)
            ->whereIn('events.status', [
                WebhookEventStatus::Processed->value,
                WebhookEventStatus::NoChange->value,
                WebhookEventStatus::Duplicate->value,
            ]);
    }

    private function database(): ConnectionInterface
    {
        return $this->connections->connection($this->databaseConnection);
    }

    private function semanticHash(string $eventType, string $semanticId): string
    {
        return hash('sha256', pack('N', strlen($eventType)).$eventType.pack('N', strlen($semanticId)).$semanticId);
    }
}
