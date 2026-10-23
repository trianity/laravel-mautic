<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Tests\Support;

use Trianity\LaravelMautic\Data\OAuthTokenRecord;

final readonly class OAuthRefreshEvidence
{
    public function __construct(
        public OAuthTokenRecord $before,
        public OAuthTokenRecord $after,
    ) {}
}
