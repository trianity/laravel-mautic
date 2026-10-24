<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Contracts;

use Illuminate\Http\Request;

interface OAuthBrowserAuthorizer
{
    public function allows(Request $request, string $connection): bool;
}
