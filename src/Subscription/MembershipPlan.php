<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Subscription;

use InvalidArgumentException;

final readonly class MembershipPlan
{
    /** @var list<MembershipRequirement> */
    public array $requirements;

    /** @param array<array-key, mixed> $requirements */
    public function __construct(array $requirements)
    {
        if ($requirements === []) {
            throw new InvalidArgumentException('A membership plan must declare at least one requirement.');
        }

        $canonical = [];
        $remote = [];
        foreach ($requirements as $requirement) {
            if (! $requirement instanceof MembershipRequirement) {
                throw new InvalidArgumentException('Membership plans accept only typed requirements.');
            }
            $key = $requirement->target->key;
            if (isset($canonical[$key]) && $canonical[$key]->payload() !== $requirement->payload()) {
                throw new InvalidArgumentException('A segment target cannot have contradictory membership requirements.');
            }
            $canonical[$key] = $requirement;

            $remoteId = $requirement->target->remoteId;
            if ($remoteId !== null && isset($remote[$remoteId]) && $remote[$remoteId] !== $requirement->presence) {
                throw new InvalidArgumentException('Distinct targets for one remote segment cannot contradict each other.');
            }
            if ($remoteId !== null) {
                $remote[$remoteId] = $requirement->presence;
            }
        }
        ksort($canonical, SORT_STRING);
        $this->requirements = array_values($canonical);
    }

    /** @return list<array{presence: string, target: array<string, mixed>}> */
    public function payload(): array
    {
        return array_map(static fn (MembershipRequirement $requirement): array => $requirement->payload(), $this->requirements);
    }
}
