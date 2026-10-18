# Laravel Mautic

[![CI](https://github.com/trianity/laravel-mautic/actions/workflows/ci.yml/badge.svg)](https://github.com/trianity/laravel-mautic/actions/workflows/ci.yml)

A model-independent Laravel integration built on the official `mautic/api-library`.
MIT licensed; intended for publication as `trianity/laravel-mautic` on Packagist.

**Status: development package, no stable release yet.** Basic Auth connections,
normalized contact reads and raw upstream contexts are implemented. Contact writes,
OAuth2, webhooks, durable synchronization and reconciliation are planned, not
available features.
No real Mautic server compatibility is claimed until the integration milestone.

## Requirements

- PHP 8.4+; preferred development runtime: PHP 8.5.
- Laravel 12 or 13; preferred development framework: Laravel 13.
- Mautic API enabled, with a dedicated integration user permitted to use Basic Auth.
- Development: Composer 2, Pest, Orchestra Testbench, Laravel Pint, Larastan.

The CI matrix selects Pest 4 on Laravel 12 and Pest 5 on Laravel 13. No Pest Laravel
plugin is necessary: package feature tests extend Testbench directly. PHP 8.4/8.5
are both tested. New PHP or Laravel majors require explicit compatibility work.

## Develop from source

```bash
git clone https://github.com/trianity/laravel-mautic.git
cd laravel-mautic
composer install
composer check
```

A library intentionally does not commit `composer.lock`. Composer resolves stable
compatible versions; a fresh default resolution prefers Laravel 13 and Pest 5.
See [CONTRIBUTING.md](CONTRIBUTING.md) for Laravel 12 development.

## Use in a host application during development

Until a release is published, add a Composer VCS repository for this GitHub URL to
the host application's root `composer.json`, then run:

```bash
composer require trianity/laravel-mautic:dev-main
php artisan vendor:publish --tag=mautic-config
```

After publication, require a tested tagged version instead of `dev-main`.
The service provider is auto-discovered. Configure the **host application's** `.env`:

```dotenv
MAUTIC_CONNECTION=main
MAUTIC_URL=https://mautic.example.com
MAUTIC_USERNAME=integration
MAUTIC_PASSWORD=replace-with-a-dedicated-secret
```

`MAUTIC_URL` is the instance root; subdirectory installs are supported. An existing
`/api` suffix is accepted. HTTPS and certificate verification are the defaults.
`allow_http` is an explicit opt-in in `config/mautic.php` for local development.
Connections are created lazily, so an unconfigured package can boot without a request.

## Normalized contact reads

```php
use Trianity\LaravelMautic\Data\ValuePresence;
use Trianity\LaravelMautic\MauticManager;
use Trianity\LaravelMautic\Read\ContactSearch;

$manager = app(MauticManager::class);
$reads = $manager->connection()->contactReads();

$contact = $reads->find($mauticContactId);
$email = $contact->field('email');
if ($email->presence === ValuePresence::Value) {
    $emailAddress = $email->value();
}

$page = $reads->search(new ContactSearch('email:person@example.com', offset: 0, limit: 25));
// $page->contacts contains every match in this page; no match is selected implicitly.

$memberships = $reads->segments($mauticContactId);
// Successful empty membership is []; API failures throw MauticReadException instead.
```

`Contact`, `ContactPage` and `SegmentMemberships` are immutable package DTOs.
`OptionalValue` distinguishes a missing property, an explicit `null`, and a present
value (including `''` or `[]`) for contact fields, tags and DNC entries. Searches
require an explicit offset and a limit from 1 to 100.

Normalized failures expose a stable `ReadFailureKind`, connection name, operation
and optional HTTP status without copying remote messages or contact payloads into the
exception. Missing contacts use `ContactNotFound`; empty membership is successful.
Every `ReadOperation` exposes side-effect and retry safety metadata, but the package
does not retry automatically.

For deterministic host tests, use `Fakes\FakeContactReader`, which implements the
same `Contracts\ContactReader` interface and can configure contacts, searches,
memberships and the next normalized failure.

Before exposing reads to a subscriber, the host application must authorize access to
the requested local contact and verify the local-to-remote identity. A remote ID,
email address or segment membership is not proof of identity or authorization.

## Raw SDK access

Raw access remains available for expert integration work:

```php
$response = $manager->connection()->contacts()->getContactSegments($mauticContactId);
```

An optional facade is available as `Trianity\LaravelMautic\Facades\Mautic`.
Additional named connections belong in `mautic.connections`:

```php
$connection = $manager->connection('another-instance');
$contacts = $connection->contacts();
$segments = $connection->api('segments'); // raw upstream escape hatch
```

**These are raw SDK contexts.** They retain upstream methods and response/error
semantics, including write/delete methods. Raw calls provide no normalized exceptions,
automatic retry, idempotency or automatic model synchronization.
Do not call raw mutations in a critical checkout path. Read the planned boundary in
[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

Replace `Contracts\HttpClientFactory` or `Contracts\ConnectionFactory` through the
container to substitute a transport or adapter. Bind replacements before resolving
the manager. Default HTTP clients have finite connect/request timeouts, TLS
verification, no redirects and no automatic retries. `Http::fake()` does not
intercept this PSR-18/Guzzle transport; use Guzzle MockHandler or a contract fake.

Named connections are cached within the manager. Call `purge('name')` after changing
configuration/credentials, or `forgetConnections()` for all connections. An old
connection reference remains old. For long-lived workers, do not mutate the global
default connection for tenant selection; resolve the explicit name for each job.

## Contact identity and sync scope

The host domain entity is a `Contact`; it need not be an authenticated `User`.
No guard, authentication model, table prefix or Mautic database schema is assumed.
Mautic's historical lead terminology is accessed through its Contact REST API.
There is no direct access to Mautic Contact/Lead database tables.

The planned identity is `(connection, local contact reference, remote contact ID)`.
Email is a discovery attribute, not a permanent identity or proof of ownership.
Subscriber-facing disclosure of segment membership requires host-side authorization
and verified identity. The package must never expose arbitrary email lookups publicly.

## Continue with Codex

Start with [AGENTS.md](AGENTS.md), then [.ai/README.md](.ai/README.md).
[.ai/prompts/00-resume.md](.ai/prompts/00-resume.md) is the local handoff prompt.
The ordered tasks describe TDD acceptance criteria and explicit implementation scope.

## License

[MIT](LICENSE.md). Dependencies retain their own licenses. This package does not
vendor or copy the Mautic SDK or the SWIS wrapper.
