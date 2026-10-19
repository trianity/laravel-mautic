<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Data;

final readonly class OAuthTokenRecord
{
    public function __construct(public OAuthToken $token, public int $generation) {}
}
