<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Reconciliation;

use RuntimeException;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Contracts\ContactMappingRepository;
use Trianity\LaravelMautic\Contracts\ReconciliationSource;
use Trianity\LaravelMautic\Exceptions\MauticReadException;
use Trianity\LaravelMautic\Exceptions\OAuthAuthenticationException;
use Trianity\LaravelMautic\Identity\RemoteContactState;

final readonly class ContactReconciler
{
    public function __construct(
        private DatabaseReconciliationStore $store,
        private ReconciliationSource $source,
        private ContactMappingRepository $mappings,
        private Clock $clock,
        private int $pageSize = 100,
        private int $minimumPageIntervalSeconds = 1,
        private int $maximumPagesPerRun = 1,
    ) {}

    public function run(string $connection, bool $restart = false): ReconciliationCheckpoint
    {
        if ($this->maximumPagesPerRun < 1 || $this->maximumPagesPerRun > 100) {
            throw new RuntimeException('The reconciliation page limit per run must be between 1 and 100.');
        }
        $checkpoint = $this->store->begin($connection, $this->pageSize, $restart);
        if ($checkpoint->status === ReconciliationStatus::Completed
            || ($checkpoint->nextPageAt !== null && $checkpoint->nextPageAt > $this->clock->now())) {
            return $checkpoint;
        }

        try {
            if ($checkpoint->highWaterRemoteId === null) {
                $checkpoint = $this->store->setHighWatermark(
                    $checkpoint,
                    $this->source->highWatermark($connection),
                    $this->minimumPageIntervalSeconds,
                );
            }
            if ($checkpoint->status === ReconciliationStatus::Completed
                || ($checkpoint->nextPageAt !== null && $checkpoint->nextPageAt > $this->clock->now())) {
                return $checkpoint;
            }

            for ($pageNumber = 0; $pageNumber < $this->maximumPagesPerRun; $pageNumber++) {
                $page = $this->source->page(
                    $connection,
                    $checkpoint->cursorRemoteId,
                    $checkpoint->highWaterRemoteId ?? 0,
                    $checkpoint->pageSize,
                );
                $rawCount = count($page->contacts);
                $contacts = [];
                foreach ($page->contacts as $contact) {
                    if ($contact->remoteId <= $checkpoint->cursorRemoteId
                        || $contact->remoteId > ($checkpoint->highWaterRemoteId ?? 0)) {
                        throw new RuntimeException('The reconciliation source returned a contact outside its requested watermark.');
                    }
                    $contacts[$contact->remoteId] = $contact;
                }
                ksort($contacts, SORT_NUMERIC);
                if ($rawCount > 0 && $contacts === []) {
                    throw new RuntimeException('The reconciliation source made no forward progress.');
                }

                $findings = [];
                foreach ($contacts as $remoteId => $contact) {
                    $mapping = $this->mappings->findByRemoteId($connection, $contact->remoteId);
                    if ($mapping === null) {
                        $findings[$remoteId] = ReconciliationFindingKind::UnmappedRemote;
                    } elseif ($mapping->remoteState !== RemoteContactState::Active) {
                        $findings[$remoteId] = ReconciliationFindingKind::MappingNotActive;
                    }
                }

                $newCursor = $contacts === [] ? $checkpoint->cursorRemoteId : (int) array_key_last($contacts);
                $complete = $rawCount === 0
                    || $rawCount < $checkpoint->pageSize
                    || $newCursor >= ($checkpoint->highWaterRemoteId ?? 0);
                $checkpoint = $this->store->recordPage(
                    $checkpoint,
                    array_keys($contacts),
                    $findings,
                    $newCursor,
                    $complete,
                    $this->minimumPageIntervalSeconds,
                );
                if ($complete || $this->minimumPageIntervalSeconds > 0) {
                    break;
                }
            }

            return $checkpoint;
        } catch (MauticReadException $exception) {
            return $this->store->fail($checkpoint, $exception->kind->value, $this->minimumPageIntervalSeconds);
        } catch (OAuthAuthenticationException $exception) {
            return $this->store->fail(
                $checkpoint,
                'oauth_'.$exception->failureKind->value,
                $this->minimumPageIntervalSeconds,
            );
        }
    }
}
