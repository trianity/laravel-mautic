<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Subscription;

use InvalidArgumentException;

final readonly class MembershipRequirement
{
    public function __construct(
        public SegmentTarget $target,
        public MembershipPresence $presence,
    ) {
        if ($presence === MembershipPresence::Absent
            && $target->policy === SegmentProvisioningPolicy::CreateIfAbsent) {
            throw new InvalidArgumentException('An absent membership requirement cannot provision a segment.');
        }
    }

    /** @return array{presence: string, target: array<string, mixed>} */
    public function payload(): array
    {
        return ['presence' => $this->presence->value, 'target' => $this->target->payload()];
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $target = $payload['target'] ?? null;
        $presence = MembershipPresence::tryFrom(is_string($payload['presence'] ?? null) ? $payload['presence'] : '');
        if (! is_array($target) || $presence === null) {
            throw new InvalidArgumentException('The stored membership requirement is invalid.');
        }

        return new self(SegmentTarget::fromPayload($target), $presence);
    }
}
