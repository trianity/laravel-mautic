<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Inbox;

use Illuminate\Contracts\Config\Repository;
use Throwable;
use Trianity\LaravelMautic\Contracts\ContactMappingRepository;
use Trianity\LaravelMautic\Contracts\InboundContactProjector;
use Trianity\LaravelMautic\Identity\RemoteContactState;

final readonly class WebhookInboxProcessor
{
    public function __construct(
        private DatabaseWebhookInboxStore $store,
        private ContactMappingRepository $mappings,
        private InboundContactProjector $projector,
        private WebhookContactMapper $contacts,
        private Repository $config,
        private int $leaseSeconds = 30,
    ) {}

    public function process(int $eventId): void
    {
        $owner = bin2hex(random_bytes(16));
        $event = $this->store->acquire($eventId, $owner, $this->leaseSeconds);
        if ($event === null) {
            return;
        }

        $contactLease = false;
        try {
            if (! $this->allowed($event)) {
                $this->store->finish($event->id, $owner, WebhookEventStatus::Ignored, 'event_not_allowed');

                return;
            }
            if ($event->remoteId === null) {
                $this->store->finish($event->id, $owner, WebhookEventStatus::Ignored, 'missing_contact_identity');

                return;
            }
            if (! $this->store->acquireContact($event->connection, $event->remoteId, $owner, $this->leaseSeconds)) {
                $this->store->defer($event->id, $owner);

                return;
            }
            $contactLease = true;
            if ($this->store->hasProcessedSemanticDuplicate($event)) {
                $this->store->finish($event->id, $owner, WebhookEventStatus::Duplicate, 'semantic_duplicate');

                return;
            }
            if ($this->store->hasProcessedNewerEvent($event)) {
                $this->store->finish($event->id, $owner, WebhookEventStatus::Stale, 'older_than_processed_event');

                return;
            }

            $mapping = $this->mappings->findByRemoteId($event->connection, $event->remoteId);
            if ($mapping === null) {
                $this->store->finish($event->id, $owner, WebhookEventStatus::Ignored, 'unmapped_contact');

                return;
            }

            if ($event->eventType === 'mautic.lead_post_delete') {
                if ($mapping->remoteState === RemoteContactState::Deleted) {
                    $this->store->finish($event->id, $owner, WebhookEventStatus::NoChange);

                    return;
                }
                $this->mappings->markRemoteState($mapping, RemoteContactState::Deleted);
                $this->store->finish($event->id, $owner, WebhookEventStatus::Processed);

                return;
            }

            if ($mapping->remoteState !== RemoteContactState::Active) {
                $this->store->finish($event->id, $owner, WebhookEventStatus::Ignored, 'mapping_not_active');

                return;
            }

            $contact = $this->contacts->map($event);
            $changes = $this->projector->mapper($mapping)
                ->resolve($this->projector->snapshot($mapping), $contact)
                ->inboundValues();
            if ($changes === []) {
                $this->store->finish($event->id, $owner, WebhookEventStatus::NoChange);

                return;
            }

            $this->projector->apply($mapping, $changes, new WebhookEventContext(
                $event->id,
                $event->receiptId,
                $event->connection,
                $event->eventType,
                $event->occurredAt,
            ));
            $this->store->finish($event->id, $owner, WebhookEventStatus::Processed);
        } catch (Throwable $exception) {
            $this->store->finish($event->id, $owner, WebhookEventStatus::Failed, 'processing_failed');

            throw $exception;
        } finally {
            if ($contactLease && $event->remoteId !== null) {
                $this->store->releaseContact($event->connection, $event->remoteId, $owner);
            }
        }
    }

    private function allowed(StoredWebhookEvent $event): bool
    {
        if ($event->eventType === 'mautic.lead_channel_subscription_changed') {
            return false;
        }
        $allowed = $this->config->get('mautic.connections.'.$event->connection.'.webhook.allowed_events', []);

        return is_array($allowed)
            && in_array($event->eventType, $allowed, true)
            && in_array($event->eventType, ['mautic.lead_post_save_update', 'mautic.lead_post_delete'], true);
    }
}
