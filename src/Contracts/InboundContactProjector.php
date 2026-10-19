<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Contracts;

use Trianity\LaravelMautic\Fields\FieldMapper;
use Trianity\LaravelMautic\Fields\HostContactFields;
use Trianity\LaravelMautic\Identity\ContactMapping;
use Trianity\LaravelMautic\Inbox\WebhookEventContext;

interface InboundContactProjector
{
    public function snapshot(ContactMapping $mapping): HostContactFields;

    public function mapper(ContactMapping $mapping): FieldMapper;

    /** @param array<string, mixed> $changes */
    public function apply(ContactMapping $mapping, array $changes, WebhookEventContext $context): void;
}
