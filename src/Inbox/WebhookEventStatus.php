<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Inbox;

enum WebhookEventStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Processed = 'processed';
    case NoChange = 'no_change';
    case Ignored = 'ignored';
    case Duplicate = 'duplicate';
    case Stale = 'stale';
    case Failed = 'failed';
}
