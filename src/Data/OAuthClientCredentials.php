<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Data;

use SensitiveParameter;

final readonly class OAuthClientCredentials
{
    public function __construct(
        public string $clientId,
        #[SensitiveParameter] public string $clientSecret,
    ) {}
}
