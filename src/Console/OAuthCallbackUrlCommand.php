<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Console;

use Illuminate\Console\Command;
use Throwable;
use Trianity\LaravelMautic\OAuth\OAuthCallbackUrlResolver;

final class OAuthCallbackUrlCommand extends Command
{
    protected $signature = 'mautic:oauth:callback-url {connection : Named Mautic OAuth2 connection}';

    protected $description = 'Display the canonical OAuth2 callback URL without starting authorization';

    public function handle(OAuthCallbackUrlResolver $callbacks): int
    {
        $connection = (string) $this->argument('connection');

        try {
            $url = $callbacks->resolve($connection);
        } catch (Throwable) {
            $this->components->error('The OAuth2 callback URL is unavailable for the requested connection. Check the named connection, redirect URI, package-route allowlist, and trusted application URL.');

            return self::FAILURE;
        }

        $this->line($url);
        $this->components->info($callbacks->usesPackageRoute($connection)
            ? 'This URL is derived from the enabled package OAuth callback route.'
            : 'This connection uses an explicit callback URL; package OAuth routes are disabled or not selected.');

        return self::SUCCESS;
    }
}
