<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Subscription;

use InvalidArgumentException;
use Trianity\LaravelMautic\Segment\StaticSegmentDefinition;

final readonly class SegmentTarget
{
    private function __construct(
        public string $key,
        public SegmentProvisioningPolicy $policy,
        public ?int $remoteId,
        public ?StaticSegmentDefinition $definition,
    ) {
        if (trim($key) === '' || strlen($key) > 191 || ($remoteId !== null && $remoteId < 1)) {
            throw new InvalidArgumentException('A bounded segment key and positive remote ID are required.');
        }
        if ($policy === SegmentProvisioningPolicy::CreateIfAbsent && $definition === null) {
            throw new InvalidArgumentException('Create-if-absent requires an explicit static segment definition.');
        }
    }

    public static function existing(string $key, ?int $remoteId = null): self
    {
        return new self($key, SegmentProvisioningPolicy::ExistingOnly, $remoteId, null);
    }

    public static function createIfAbsent(string $key, StaticSegmentDefinition $definition): self
    {
        return new self($key, SegmentProvisioningPolicy::CreateIfAbsent, null, $definition);
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'key' => $this->key,
            'policy' => $this->policy->value,
            'remote_id' => $this->remoteId,
            'definition' => $this->definition === null ? null : [
                'name' => $this->definition->name,
                'alias' => $this->definition->alias,
                'global' => $this->definition->global,
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $definition = $payload['definition'] ?? null;
        $parsedDefinition = is_array($definition)
            ? new StaticSegmentDefinition(
                is_string($definition['name'] ?? null) ? $definition['name'] : '',
                is_string($definition['alias'] ?? null) ? $definition['alias'] : '',
                is_bool($definition['global'] ?? null) ? $definition['global'] : true,
            )
            : null;
        $key = is_string($payload['key'] ?? null) ? $payload['key'] : '';
        $remoteId = is_int($payload['remote_id'] ?? null) ? $payload['remote_id'] : null;
        $policy = SegmentProvisioningPolicy::tryFrom(is_string($payload['policy'] ?? null) ? $payload['policy'] : '');

        return match ($policy) {
            SegmentProvisioningPolicy::ExistingOnly => new self($key, $policy, $remoteId, null),
            SegmentProvisioningPolicy::CreateIfAbsent => new self($key, $policy, null, $parsedDefinition),
            null => throw new InvalidArgumentException('The stored segment target policy is invalid.'),
        };
    }
}
