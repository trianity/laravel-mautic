<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Outbox;

final readonly class OutboxPoller
{
    public function __construct(
        private DatabaseOutboxStore $store,
        private OutboxDispatcher $dispatcher,
    ) {}

    public function recover(int $limit = 100): int
    {
        $dispatched = 0;
        foreach ($this->store->recoverableOperationIds($limit) as $operationId) {
            if ($this->dispatcher->dispatch($operationId)) {
                $dispatched++;
            }
        }

        return $dispatched;
    }
}
