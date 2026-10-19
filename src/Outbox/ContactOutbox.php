<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Outbox;

final readonly class ContactOutbox
{
    public function __construct(
        private DatabaseOutboxStore $store,
        private OutboxDispatcher $dispatcher,
    ) {}

    public function record(ContactSyncCommand $command): OutboxRecord
    {
        $record = $this->store->record($command);
        $this->store->afterCommit(function () use ($command): void {
            $this->dispatcher->dispatch($command->operationId);
        });

        return $record;
    }
}
