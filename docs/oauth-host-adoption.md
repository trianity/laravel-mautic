# Adopting the optional package OAuth browser integration

Version 1.1 adds reusable browser endpoints without changing the 1.0 behavior. Routes are
absent until explicitly enabled, and an explicit per-connection `redirect_uri` continues
to support a host-owned controller and callback indefinitely.

## Configuration

Publish `mautic-config`, retain the existing OAuth database migration, and configure the
route block shown in the README. When upgrading a host with an already published
`config/mautic.php`, do not republish it with `--force`: manually add the `oauth.routes`
block and compare new defaults so host-specific connections and settings are not
overwritten. The host middleware list must include its session-enabled web stack, CSRF
protection for the start POST, and any normal authentication middleware.
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

Bind that class to `OAuthBrowserAuthorizer` in a host service provider:

```php
use Domain\Mautic\Services\MauticOAuthAuthorizer;
use Trianity\LaravelMautic\Contracts\OAuthBrowserAuthorizer;

public function register(): void
{
    $this->app->bind(OAuthBrowserAuthorizer::class, MauticOAuthAuthorizer::class);
}
```

The example ability and guard are host choices, not package assumptions. Set
`result_route` to the host's existing administration page so it can render the fixed
`mautic_oauth` flash outcome. A Blade administration page can start the named POST route
without exposing callback state:

```blade
<form method="POST" action="{{ route('mautic.oauth.start', ['connection' => 'main']) }}">
    @csrf
    <button type="submit">Connect Mautic</button>
</form>
```

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

An explicit per-connection `redirect_uri`, including a legacy value retained in published
configuration, always overrides the package-derived callback. Remove it or set it to `null`
when adopting package routes. The derived path includes the connection name, for example
`https://crm.example.com/mautic/oauth/main/callback`; register the command's exact output,
not a former host callback without `/main/`.

## First OAuth connection

1. In Mautic's configuration, enable the API and OAuth2 authorization-code access. Create
   or choose a dedicated integration user with only the permissions required by the host.
2. Publish and run `mautic-oauth-migrations` if the host has not already installed the
   package OAuth tables. Do not edit a migration that has run in a shared environment.
3. Choose the connection name and route prefix, set the host's trusted `app.url`, and add
   the `oauth.routes` block manually when upgrading a previously published configuration.
   The intended callback is `{app.url}/{prefix}/{connection}/callback`; it includes the
   connection name, such as `https://crm.example.com/mautic/oauth/main/callback`.
4. In Mautic's API credentials screen, create the OAuth client and register that callback.
   Use separate clients for environments with different callback origins. Mautic supplies
   the client ID and secret needed by the host configuration.
5. Complete the OAuth2 connection in `config/mautic.php` with the Mautic URL, supplied
   `client_id`, literal `client_secret_env` name and scopes. Leave `redirect_uri` unset or
   `null`. Add the runtime client secret to every relevant process environment. Bind the
   host `OAuthBrowserAuthorizer`, configure session/CSRF/authentication middleware,
   allowlist `main`, and configure a trusted named `result_route`.
6. Run `php artisan mautic:oauth:callback-url main` and compare its output byte for byte
   with the callback registered in Mautic. If the command reports an explicit URI, remove
   the legacy override before continuing with package routes. Correct the Mautic client
   registration if the derived URI differs.
7. Clear and rebuild Laravel's configuration cache if used, restart long-running workers,
   then submit the CSRF-protected named start route from an authorized administration
   session. Approve access in Mautic and return to the configured result page.
8. Confirm the result page reports `mautic_oauth.status=authorized`, rerun
   `mautic:diagnose`, and exercise one bounded host read in a fresh CLI process and, when
   applicable, a real queue worker. A failed result is not an empty successful connection;
   resolve its sanitized reason before removing old host OAuth code.

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
