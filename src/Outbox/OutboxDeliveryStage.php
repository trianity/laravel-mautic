<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Outbox;

enum OutboxDeliveryStage: string
{
    case Recorded = 'recorded';
    case OperationInFlight = 'operation_in_flight';
    case RemoteSucceeded = 'remote_succeeded';
}
