<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Subscription;

use InvalidArgumentException;
use Trianity\LaravelMautic\Identity\LocalContactReference;
use Trianity\LaravelMautic\Write\ContactFields;

final readonly class MembershipPlanCommand
{
    public function __construct(
        public string $connection,
        public LocalContactReference $localReference,
        public string $eventKey,
        public int $contactRevision,
        public ContactFields $contactFields,
        public MembershipPlan $plan,
        public string $source = 'host',
    ) {
        if (trim($connection) === '' || strlen($connection) > 191
            || trim($eventKey) === '' || strlen($eventKey) > 191
            || $contactRevision < 1 || trim($source) === '' || strlen($source) > 64) {
            throw new InvalidArgumentException('Membership plan identity, revision and source must be valid bounded values.');
        }
    }

    public function operationId(): string
    {
        return 'sub-'.substr(hash('sha256', $this->connection."\0".$this->localReference->scope."\0".$this->localReference->key."\0".$this->eventKey), 0, 60);
    }

    public function semanticHash(): string
    {
        $fields = $this->contactFields->toPayload();
        ksort($fields);

        return hash('sha256', json_encode([
            'connection' => $this->connection,
            'scope' => $this->localReference->scope,
            'key' => $this->localReference->key,
            'event' => $this->eventKey,
            'revision' => $this->contactRevision,
            'fields' => $fields,
            'membership_plan' => $this->plan->payload(),
            'source' => $this->source,
        ], JSON_THROW_ON_ERROR));
    }
}
