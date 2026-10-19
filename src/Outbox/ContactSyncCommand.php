<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Outbox;

use Trianity\LaravelMautic\Exceptions\InvalidOutboxCommand;
use Trianity\LaravelMautic\Identity\ContactIdentityHasher;
use Trianity\LaravelMautic\Identity\LocalContactReference;
use Trianity\LaravelMautic\Write\ContactFields;

final readonly class ContactSyncCommand
{
    private function __construct(
        public string $operationId,
        public string $connection,
        public LocalContactReference $localReference,
        public int $revision,
        public ContactFields $fields,
        public OutboxOperation $operation,
        public string $source,
    ) {
        if (trim($operationId) === '' || strlen($operationId) > 64
            || trim($connection) === '' || strlen($connection) > 191
            || $revision < 1 || trim($source) === '' || strlen($source) > 64) {
            throw new InvalidOutboxCommand('Outbox identity, connection, revision and source must be valid bounded values.');
        }
    }

    public static function create(
        string $operationId,
        string $connection,
        LocalContactReference $localReference,
        int $revision,
        ContactFields $fields,
        string $source = 'host',
    ): self {
        return new self($operationId, $connection, $localReference, $revision, $fields, OutboxOperation::Create, $source);
    }

    public static function update(
        string $operationId,
        string $connection,
        LocalContactReference $localReference,
        int $revision,
        ContactFields $fields,
        string $source = 'host',
    ): self {
        return new self($operationId, $connection, $localReference, $revision, $fields, OutboxOperation::Update, $source);
    }

    public function aggregateHash(): string
    {
        return ContactIdentityHasher::local($this->connection, $this->localReference);
    }
}
