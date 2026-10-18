<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Data;

final readonly class DoNotContactEntry
{
    /**
     * @param  OptionalValue<int|string>  $reason
     * @param  OptionalValue<string>  $comments
     */
    public function __construct(
        public ?int $remoteId,
        public string $channel,
        public OptionalValue $reason,
        public OptionalValue $comments,
    ) {}
}
