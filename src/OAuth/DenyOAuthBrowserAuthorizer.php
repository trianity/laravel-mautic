<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\OAuth;

use Illuminate\Http\Request;
use Trianity\LaravelMautic\Contracts\OAuthBrowserAuthorizer;

final readonly class DenyOAuthBrowserAuthorizer implements OAuthBrowserAuthorizer
{
    public function allows(Request $request, string $connection): bool
    {
        return false;
    }
}
