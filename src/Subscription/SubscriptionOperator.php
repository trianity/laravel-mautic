<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Subscription;

use Trianity\LaravelMautic\Contracts\SubscriptionTransport;
use Trianity\LaravelMautic\Exceptions\SegmentConflict;

final readonly class SubscriptionOperator
{
    public function __construct(
        private DatabaseSubscriptionStore $store,
        private SubscriptionTransport $transport,
    ) {}

    public function resumeMembershipFailure(string $operationId): void
    {
        $this->store->resumeMembershipFailure($operationId);
    }

    public function associateAmbiguousCreate(
        string $operationId,
        string $connection,
        string $segmentKey,
        int $remoteId,
    ): void {
        $segment = $this->transport->segments($connection)->find($remoteId);
        if (! $segment->suitable()) {
            throw new SegmentConflict('Only a published empty-filter segment may be associated.');
        }
        $definitionHash = hash('sha256', json_encode([
            $segment->name, $segment->alias, $segment->published, $segment->global, $segment->filters,
        ], JSON_THROW_ON_ERROR));
        $this->store->associateSegment($connection, $segmentKey, $remoteId, $definitionHash);
        $this->store->resumeAfterSegmentAssociation($operationId);
    }
}
