<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Subscription;

final readonly class SubscriptionCapture
{
    public function __construct(public SubscriptionRecord $record, public bool $new) {}
}
