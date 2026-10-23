<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Restriction;

use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Exceptions\InvalidWriteRequest;

final readonly class ManualEmailBlock
{
    private const MARKER = 'trianity/laravel-mautic';

    private function __construct(
        public string $explanation,
        public string $recordedAt,
    ) {}

    public static function record(string $explanation, Clock $clock): self
    {
        if (trim($explanation) === '') {
            throw new InvalidWriteRequest('A manual email block requires a non-empty human explanation.');
        }

        return new self($explanation, gmdate('Y-m-d\TH:i:s\Z', $clock->now()));
    }

    public function comments(): string
    {
        return $this->explanation."\n\n[".self::MARKER.'; recorded_at='.$this->recordedAt.']';
    }
}
