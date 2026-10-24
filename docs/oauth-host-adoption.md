# Adopting the optional package OAuth browser integration

Version 1.1 adds reusable browser endpoints without changing the 1.0 behavior. Routes are
absent until explicitly enabled, and an explicit per-connection `redirect_uri` continues
to support a host-owned controller and callback indefinitely.

## Configuration

Publish `mautic-config`, retain the existing OAuth database migration, and configure the
route block shown in the README. The host middleware list must include its session-enabled
web stack, CSRF protection for the start POST, and any normal authentication middleware.
Bind `Trianity\LaravelMautic\Contracts\OAuthBrowserAuthorizer` to a host class that performs
the final administration authorization decision for the named connection. With no host
binding, the package denies access.

Keep the implementation in the host's existing `Domain\Mautic` convention, for example:

```php
namespace Domain\Mautic\Services;

use Illuminate\Http\Request;
use Trianity\LaravelMautic\Contracts\OAuthBrowserAuthorizer;

final readonly class MauticOAuthAuthorizer implements OAuthBrowserAuthorizer
{
    public function allows(Request $request, string $connection): bool
    {
        return $connection === 'main'
            && $request->user('web')?->can('administer-mautic') === true;
    }
}
```

Bind that class to `OAuthBrowserAuthorizer` in a host provider. The example ability and
guard are host choices, not package assumptions. Set `result_route` to the host's existing
administration page so it can render the fixed `mautic_oauth` flash outcome.

Keep secrets out of configuration. The default mapping is:

```dotenv
MAUTIC_CONNECTION=main
MAUTIC_URL=https://mautic.example.com
MAUTIC_OAUTH_CLIENT_ID=public-client-id
MAUTIC_OAUTH_CLIENT_SECRET=runtime-secret
# Optional only for a host-owned or otherwise explicit callback:
MAUTIC_OAUTH_REDIRECT_URI=https://crm.example.com/kezelo/integrations/mautic/oauth/callback
```

The published connection sets `client_secret_env` to the literal name
`MAUTIC_OAUTH_CLIENT_SECRET`; the package resolves its value at runtime. If package routes
should derive the callback, omit `redirect_uri` (or configure it as `null`) rather than
storing the secret or an empty placeholder in cached configuration. The origin comes from
trusted `app.url`; behind a base path that path must be present there.

Run `php artisan mautic:oauth:callback-url main` and register the printed URI exactly in
the Mautic OAuth client. Use different client configuration for local and production
origins. With `config:cache`, PHP-FPM, CLI, scheduler and queue workers all need the secret
in their process environment because Laravel does not reload `.env`. Restart workers after
environment or cached-configuration changes.

## Safe adoption order

1. Install the released 1.1 package version; do not deploy `dev-main` or patch `vendor`.
2. Configure the allowlist, middleware, host authorizer, trusted result route and canonical
   callback. Exercise the package start and callback endpoints while the old host code is
   still available for rollback.
3. Remove only the superseded infrastructure listed below.
4. Rerun the host's offline and real disposable acceptance, including a fresh CLI process
   and a real queue worker.
5. Deploy without deleting token rows or unnecessarily reauthorizing the OAuth client.

For the inspected `/home/holbok/web/negy` host, removal is safe only after the released
package is installed and its host acceptance passes:

- remove `src/Domain/Mautic/Services/LaravelEnvironmentOAuthClientCredentialsProvider.php`
  and its `OAuthClientCredentialsProvider` binding in `app/Providers/AppServiceProvider.php`;
- remove the duplicate `start()` and `callback()` behavior from
  `src/Domain/Mautic/Http/Controllers/MauticOAuthController.php`, plus the corresponding
  POST/GET route declarations in `routes/admin.php`; retain the host status page action;
- remove `src/Domain/Mautic/Contracts/MauticOAuthGateway.php`,
  `src/Domain/Mautic/Services/PackageMauticOAuthGateway.php`, and their provider binding once
  no host-owned OAuth endpoint uses them;
- replace the superseded portions of
  `tests/Feature/LaravelEnvironmentOAuthClientCredentialsProviderTest.php` and
  `tests/Feature/MauticOAuthControllerTest.php` with host tests for its authorizer,
  middleware, result page and package routes.

The inspected `Domain\Mautic\Commands\VerifyMauticConnection` remains host-owned: it performs
a business-authorized bounded contact read and optional queue dispatch, so the package's
read-only callback URL command does not replace it.

The following also stay in the host: the `Domain\Mautic` administration UI and access
rules, contact identity and field mapping, free/paid subscription actions and stable segment
keys, scheduler/worker configuration, and real host acceptance tests. Do not reset encrypted
token storage, replay authorization codes, or reauthorize solely because the routing layer
moved.
