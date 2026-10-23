<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Subscription;

use Trianity\LaravelMautic\Contracts\ContactMappingRepository;
use Trianity\LaravelMautic\Identity\RemoteContactState;
use Trianity\LaravelMautic\Outbox\ContactOutbox;
use Trianity\LaravelMautic\Outbox\ContactSyncCommand;

final readonly class SubscriptionDelivery
{
    public function __construct(
        private DatabaseSubscriptionStore $store,
        private ContactOutbox $contactOutbox,
        private ContactMappingRepository $mappings,
        private SubscriptionDispatcher $dispatcher,
    ) {}

    public function record(SubscriptionCommand $command): SubscriptionRecord
    {
        $this->store->requireTransaction();
        $contactOperationId = 'contact-'.substr(hash('sha256', $command->operationId()), 0, 56);
        $mapping = $this->mappings->find($command->connection, $command->localReference);
        $contact = $mapping !== null && $mapping->remoteState === RemoteContactState::Active
            ? ContactSyncCommand::update(
                $contactOperationId,
                $command->connection,
                $command->localReference,
                $command->contactRevision,
                $command->contactFields,
                'subscription',
            )
            : ContactSyncCommand::create(
                $contactOperationId,
                $command->connection,
                $command->localReference,
                $command->contactRevision,
                $command->contactFields,
                'subscription',
            );
        $capture = $this->store->record($command, $contactOperationId);
        $record = $capture->record;
        if (! $capture->new) {
            return $record;
        }
        $this->contactOutbox->record($contact);
        $this->store->afterCommit(function () use ($record): void {
            $this->dispatcher->dispatch($record->operationId);
        });

        return $record;
    }
}
