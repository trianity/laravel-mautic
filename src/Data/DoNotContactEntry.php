<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Data;

final readonly class DoNotContactEntry
{
    /**
     * @param  OptionalValue<int>  $remoteId
     * @param  OptionalValue<int|string>  $reason
     * @param  OptionalValue<string>  $dateAdded
     * @param  OptionalValue<int>  $channelId
     * @param  OptionalValue<string>  $comments
     */
    public function __construct(
        public OptionalValue $remoteId,
        public string $channel,
        public OptionalValue $reason,
        public OptionalValue $dateAdded,
        public OptionalValue $channelId,
        public OptionalValue $comments,
    ) {}

    public function knownReason(): ?DoNotContactReason
    {
        if ($this->reason->presence !== ValuePresence::Value) {
            return null;
        }

        $reason = $this->reason->value();
        if (is_string($reason) && preg_match('/^(0|[1-9][0-9]*)$/D', $reason) === 1) {
            $reason = (int) $reason;
        }

        return is_int($reason) ? DoNotContactReason::tryFrom($reason) : null;
    }

    public function bounceEvidence(): BounceEvidence
    {
        return match ($this->knownReason()) {
            DoNotContactReason::Bounced => BounceEvidence::SubtypeUnavailable,
            DoNotContactReason::Contactable,
            DoNotContactReason::Unsubscribed,
            DoNotContactReason::Manual => BounceEvidence::NotBounced,
            null => BounceEvidence::Unavailable,
        };
    }
}
