<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Reconciliation;

use RuntimeException;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Contracts\ContactMappingRepository;
use Trianity\LaravelMautic\Contracts\ReconciliationSource;
use Trianity\LaravelMautic\Exceptions\MauticReadException;
use Trianity\LaravelMautic\Exceptions\OAuthAuthenticationException;
use Trianity\LaravelMautic\Exceptions\OAuthReauthorizationRequired;
use Trianity\LaravelMautic\Exceptions\OAuthTokenPersistenceUncertain;
use Trianity\LaravelMautic\Exceptions\OAuthTokenRequestFailed;
use Trianity\LaravelMautic\Identity\RemoteContactState;
use Trianity\LaravelMautic\OAuth\OAuthTokenFailureKind;
use Trianity\LaravelMautic\Read\ReadFailureKind;
use Trianity\LaravelMautic\Read\ReadOperation;

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
        private int $requestReservationSeconds = 30,
    ) {}

    public function run(string $connection, bool $restart = false): ReconciliationCheckpoint
    {
        if ($this->maximumPagesPerRun < 1 || $this->maximumPagesPerRun > 100) {
            throw new RuntimeException('The reconciliation page limit per run must be between 1 and 100.');
        }
        $checkpoint = $this->store->begin($connection, $this->pageSize, $restart);
        $pages = 0;
        while (true) {
            if (in_array($checkpoint->status, [ReconciliationStatus::Completed, ReconciliationStatus::Blocked], true)
                || ($checkpoint->nextPageAt !== null && $checkpoint->nextPageAt > $this->clock->now())) {
                return $checkpoint;
            }
            $reservation = $this->store->reserve(
                $checkpoint,
                $this->minimumPageIntervalSeconds,
                $this->requestReservationSeconds,
            );
            if ($reservation === null) {
                return $this->store->get($connection) ?? $checkpoint;
            }

            try {
                if ($reservation->highWaterRemoteId === null) {
                    $checkpoint = $this->store->setHighWatermark(
                        $reservation,
                        $this->source->highWatermark($connection),
                    );
                    if ($checkpoint->status === ReconciliationStatus::Completed
                        || $this->minimumPageIntervalSeconds > 0) {
                        return $checkpoint;
                    }

                    continue;
                }

                $page = $this->source->page(
                    $connection,
                    $reservation->cursorRemoteId,
                    $reservation->highWaterRemoteId,
                    $reservation->pageSize,
                );
                $rawCount = count($page->contacts);
                if ($page->remainingCount < $rawCount
                    || $rawCount > $reservation->pageSize
                    || ($rawCount === 0 && $page->remainingCount > 0)) {
                    throw new MauticReadException(
                        ReadFailureKind::UnexpectedResponse,
                        ReadOperation::SearchContacts,
                        $connection,
                    );
                }
                $contacts = [];
                $previous = $reservation->cursorRemoteId;
                foreach ($page->contacts as $contact) {
                    if ($contact->remoteId <= $previous
                        || $contact->remoteId > $reservation->highWaterRemoteId) {
                        throw new MauticReadException(
                            ReadFailureKind::UnexpectedResponse,
                            ReadOperation::SearchContacts,
                            $connection,
                        );
                    }
                    $contacts[$contact->remoteId] = $contact;
                    $previous = $contact->remoteId;
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

                $newCursor = $contacts === [] ? $reservation->cursorRemoteId : (int) array_key_last($contacts);
                $complete = $page->remainingCount === $rawCount
                    || $newCursor >= $reservation->highWaterRemoteId;
                $checkpoint = $this->store->recordPage(
                    $reservation,
                    array_keys($contacts),
                    $findings,
                    $newCursor,
                    $complete,
                );
                $pages++;
                if ($complete || $this->minimumPageIntervalSeconds > 0 || $pages >= $this->maximumPagesPerRun) {
                    return $checkpoint;
                }
            } catch (MauticReadException $exception) {
                return $this->store->fail($reservation, $exception->kind->value, $exception->retryAfterSeconds);
            } catch (OAuthTokenPersistenceUncertain) {
                return $this->store->block($reservation, 'oauth_token_persistence_uncertain');
            } catch (OAuthReauthorizationRequired) {
                return $this->store->block($reservation, 'oauth_reauthorization_required');
            } catch (OAuthTokenRequestFailed $exception) {
                if (! in_array($exception->kind, [
                    OAuthTokenFailureKind::Throttled,
                    OAuthTokenFailureKind::RemoteServer,
                    OAuthTokenFailureKind::Network,
                ], true)) {
                    return $this->store->block($reservation, 'oauth_'.$exception->kind->value);
                }

                return $this->store->fail(
                    $reservation,
                    'oauth_'.$exception->kind->value,
                    $exception->retryAfterSeconds,
                );
            } catch (OAuthAuthenticationException $exception) {
                return $this->store->fail($reservation, 'oauth_'.$exception->failureKind->value);
            }
        }
    }
}
