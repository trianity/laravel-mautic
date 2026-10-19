<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Outbox;

enum OutboxOperation: string
{
    case Create = 'create';
    case Update = 'update';
}
