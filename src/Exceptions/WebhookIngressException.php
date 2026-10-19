<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Exceptions;

use RuntimeException;
use Throwable;
use Trianity\LaravelMautic\Inbox\WebhookIngressFailureKind;

final class WebhookIngressException extends RuntimeException
{
    public function __construct(public readonly WebhookIngressFailureKind $kind, ?Throwable $previous = null)
    {
        parent::__construct($kind->message(), previous: $previous);
    }
}
