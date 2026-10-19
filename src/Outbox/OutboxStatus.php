<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Outbox;

enum OutboxStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case RetryWait = 'retry_wait';
    case Completed = 'completed';
    case Failed = 'failed';
    case Ambiguous = 'ambiguous';
    case Blocked = 'blocked';
    case Superseded = 'superseded';

    public function terminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Ambiguous, self::Blocked, self::Superseded], true);
    }
}
