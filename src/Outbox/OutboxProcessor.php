<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Outbox;

use Throwable;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Contracts\ContactMappingRepository;
use Trianity\LaravelMautic\Contracts\ContactSyncTransport;
use Trianity\LaravelMautic\Exceptions\AmbiguousMutationOutcome;
use Trianity\LaravelMautic\Exceptions\ContactMappingConflict;
use Trianity\LaravelMautic\Exceptions\MauticReadException;
use Trianity\LaravelMautic\Exceptions\MauticWriteException;
use Trianity\LaravelMautic\Exceptions\OAuthAuthenticationException;
use Trianity\LaravelMautic\Identity\RemoteContactState;
use Trianity\LaravelMautic\Read\ReadFailureKind;
use Trianity\LaravelMautic\Write\ContactFields;
use Trianity\LaravelMautic\Write\FailureKind;

final readonly class OutboxProcessor
{
    public function __construct(
        private DatabaseOutboxStore $store,
        private ContactSyncTransport $transport,
        private ContactMappingRepository $mappings,
        private Clock $clock,
        private int $leaseSeconds = 30,
        private int $maxAttempts = 5,
        private int $maximumDelaySeconds = 300,
    ) {
        if ($leaseSeconds < 1 || $maxAttempts < 1 || $maximumDelaySeconds < 1) {
            throw new \InvalidArgumentException('Outbox lease, attempt and delay limits must be positive.');
        }
    }

    public function process(string $operationId): void
    {
        $initial = $this->store->get($operationId);
        if ($initial === null || $initial->status->terminal()) {
            return;
        }
        $owner = bin2hex(random_bytes(16));
        if (! $this->store->acquire($initial->aggregateHash, $owner, $this->leaseSeconds)) {
            return;
        }

        try {
            $current = $this->store->get($operationId);
            if ($current === null || $current->status->terminal()) {
                return;
            }
            if ($current->status === OutboxStatus::Processing
                && ($current->leaseExpiresAt ?? PHP_INT_MAX) <= $this->clock->now()
                && $current->operation === OutboxOperation::Create
                && $this->mappings->find($current->connection, $current->localReference) === null) {
                $this->store->ambiguous($operationId, null, 'create_worker_lost');

                return;
            }

            $record = $this->store->begin($current, $owner, $this->leaseSeconds);
            if ($record === null) {
                return;
            }
            $fields = ContactFields::fromArray($record->payload, array_keys($record->payload));
            $mapping = $this->mappings->find($record->connection, $record->localReference);
            if ($mapping !== null && $mapping->remoteState !== RemoteContactState::Active) {
                $this->store->fail($operationId, $owner, FailureKind::TargetNotFound->value, null, 'mapping_not_active');

                return;
            }

            try {
                if ($mapping !== null) {
                    $this->transport->update($record->connection, $mapping->remoteId, $fields);
                    $remoteId = $mapping->remoteId;
                } elseif ($record->operation === OutboxOperation::Create) {
                    $remoteId = $this->transport->create($record->connection, $fields);
                } else {
                    $this->store->fail($operationId, $owner, FailureKind::TargetNotFound->value, null, 'mapping_missing');

                    return;
                }
            } catch (AmbiguousMutationOutcome $exception) {
                $this->handleFailure($record, $owner, $exception);

                return;
            } catch (MauticWriteException $exception) {
                $this->handleFailure($record, $owner, $exception);

                return;
            } catch (OAuthAuthenticationException) {
                $this->scheduleRetryOrFail($record, $owner, FailureKind::Authentication, null, null, 'authentication_failed');

                return;
            } catch (MauticReadException $exception) {
                $kind = match ($exception->kind) {
                    ReadFailureKind::Authentication => FailureKind::Authentication,
                    ReadFailureKind::Validation => FailureKind::Validation,
                    ReadFailureKind::Timeout => FailureKind::Timeout,
                    ReadFailureKind::Throttled => FailureKind::Throttled,
                    ReadFailureKind::MalformedResponse => FailureKind::MalformedResponse,
                    ReadFailureKind::UnexpectedResponse => FailureKind::UnexpectedResponse,
                    ReadFailureKind::RemoteServer => FailureKind::RemoteServer,
                    ReadFailureKind::Transport => FailureKind::Transport,
                    ReadFailureKind::NotFound => FailureKind::TargetNotFound,
                };
                $this->scheduleRetryOrFail(
                    $record,
                    $owner,
                    $kind,
                    $exception->httpStatus,
                    $exception->retryAfterSeconds,
                    'create_preflight_'.$kind->value,
                    allowCreateTransportRetry: true,
                );

                return;
            }

            if ($mapping === null) {
                try {
                    $this->mappings->claim($record->connection, $record->localReference, $remoteId);
                } catch (ContactMappingConflict) {
                    $this->store->ambiguous($operationId, $owner, 'create_mapping_conflict');

                    return;
                }
            }
            $this->store->complete($operationId, $owner, $remoteId);
        } catch (Throwable $exception) {
            $record = $this->store->get($operationId);
            if ($record?->status === OutboxStatus::Processing && $record->leaseOwner === $owner) {
                $this->store->fail($operationId, $owner, FailureKind::UnexpectedResponse->value, null, 'processor_failure');
            }

            throw $exception;
        } finally {
            $this->store->release($initial->aggregateHash, $owner);
        }
    }

    private function handleFailure(OutboxRecord $record, string $owner, MauticWriteException $exception): void
    {
        $kind = $exception->kind;
        if ($record->operation === OutboxOperation::Create
            && in_array($kind, [
                FailureKind::AmbiguousOutcome,
                FailureKind::Timeout,
                FailureKind::Transport,
                FailureKind::DuplicateContact,
            ], true)) {
            $this->store->ambiguous($record->operationId, $owner, 'create_outcome_ambiguous');

            return;
        }

        $code = match ($kind) {
            FailureKind::Authentication => 'authentication_failed',
            FailureKind::Validation => 'remote_validation',
            FailureKind::Throttled => 'remote_throttled',
            FailureKind::RemoteServer => 'remote_server',
            FailureKind::Timeout => 'remote_timeout',
            FailureKind::Transport => 'remote_transport',
            FailureKind::TargetNotFound => 'remote_target_missing',
            FailureKind::DuplicateContact => 'remote_duplicate',
            FailureKind::MalformedResponse => 'malformed_response',
            FailureKind::UnexpectedResponse => 'unexpected_response',
            FailureKind::AmbiguousOutcome => 'ambiguous_outcome',
        };
        $this->scheduleRetryOrFail(
            $record,
            $owner,
            $kind,
            $exception->httpStatus,
            $exception->retryAfterSeconds,
            $code,
        );
    }

    private function scheduleRetryOrFail(
        OutboxRecord $record,
        string $owner,
        FailureKind $kind,
        ?int $httpStatus,
        ?int $retryAfter,
        string $errorCode,
        bool $allowCreateTransportRetry = false,
    ): void {
        $retryable = in_array($kind, [
            FailureKind::Authentication,
            FailureKind::Throttled,
            FailureKind::RemoteServer,
        ], true) || ($record->operation === OutboxOperation::Update
            && in_array($kind, [FailureKind::Timeout, FailureKind::Transport, FailureKind::AmbiguousOutcome], true))
            || ($allowCreateTransportRetry && in_array($kind, [FailureKind::Timeout, FailureKind::Transport], true));
        if (! $retryable || $record->attempts >= $this->maxAttempts) {
            $this->store->fail(
                $record->operationId,
                $owner,
                $kind->value,
                $httpStatus,
                $record->attempts >= $this->maxAttempts ? 'attempts_exhausted' : $errorCode,
            );

            return;
        }

        $delay = $retryAfter === null
            ? min($this->maximumDelaySeconds, (5 * (2 ** max(0, $record->attempts - 1))) + (crc32($record->operationId) % 3))
            : min($this->maximumDelaySeconds, max(1, $retryAfter));
        $this->store->retry(
            $record->operationId,
            $owner,
            $this->clock->now() + $delay,
            $kind->value,
            $httpStatus,
            $errorCode,
        );
    }
}
