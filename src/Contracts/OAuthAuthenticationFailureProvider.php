<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Contracts;

use Trianity\LaravelMautic\Exceptions\OAuthAuthenticationException;

interface OAuthAuthenticationFailureProvider
{
    public function pullAuthenticationFailure(): ?OAuthAuthenticationException;
}
