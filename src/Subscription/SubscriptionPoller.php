<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Subscription;

final readonly class SubscriptionPoller
{
    public function __construct(private DatabaseSubscriptionStore $store, private SubscriptionDispatcher $dispatcher) {}

    public function recover(int $limit = 100): int
    {
        $count = 0;
        foreach ($this->store->recoverable($limit) as $operationId) {
            if ($this->dispatcher->dispatch($operationId)) {
                $count++;
            }
        }

        return $count;
    }
}
