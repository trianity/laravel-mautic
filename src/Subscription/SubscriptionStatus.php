<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Subscription;

enum SubscriptionStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case RetryWait = 'retry_wait';
    case Completed = 'completed';
    case Failed = 'failed';
    case Blocked = 'blocked';
    case Ambiguous = 'ambiguous';

    public function terminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Blocked, self::Ambiguous], true);
    }
}
