# Laravel Mautic

[![CI](https://github.com/trianity/laravel-mautic/actions/workflows/ci.yml/badge.svg)](https://github.com/trianity/laravel-mautic/actions/workflows/ci.yml)

A model-independent Laravel integration built on the official `mautic/api-library`.
MIT licensed; intended for publication as `trianity/laravel-mautic` on Packagist.

**Status: development package, no stable release yet.** Basic Auth and opt-in OAuth2
connections, normalized contact reads, explicit contact writes, manual segment
commands, durable identity mappings, field ownership rules, transactional outgoing
synchronization and raw upstream contexts are implemented. Webhooks, incoming
synchronization and general reconciliation are planned, not available features.
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

## OAuth2 authorization and token lifecycle

OAuth2 uses Mautic's authorization-code and refresh-token grants; this package does
not use or assume `client_credentials`. Publish and run the optional namespaced
storage migration before enabling an OAuth2 connection:

```bash
php artisan vendor:publish --tag=mautic-migrations
php artisan migrate
```

The current development migration includes the state `context_hash` and token
`generation` columns. If an older development stub was already published, replace it
before running it. If it was already run, add those columns with a host migration;
do not edit a migration that has run in a shared environment.

Set the connection's `auth` to `oauth2` and copy the commented `oauth` block from
`config/mautic.php`. `client_secret_env` is the **name** of a runtime environment
variable, not its value. The default credential provider reads the secret with
`getenv()` when needed, so neither tokens nor the OAuth client secret enter the
published configuration or Laravel's configuration cache. Applications using a
secret manager may bind `Contracts\OAuthClientCredentialsProvider` instead.

The host owns the interactive route and authorization decision. Start authorization
only from an explicitly authorized browser action, then redirect the browser to the
returned URL:

```php
use Trianity\LaravelMautic\OAuth\OAuth2Service;

$oauth = app(OAuth2Service::class);
$authorizationContext = bin2hex(random_bytes(32));
session()->put('mautic.oauth.main.context', $authorizationContext);
$authorizationUrl = $oauth->authorizationUrl('main', $authorizationContext);
// The host returns its own redirect response to $authorizationUrl.
```

In the configured callback, retrieve the context from trusted host-side state. Never
accept the context from a query string, form field or other callback input:

```php
$authorizationContext = session()->pull('mautic.oauth.main.context');

$oauth->complete(
    'main',
    (string) request('state'),
    (string) request('code'),
    is_string($authorizationContext) ? $authorizationContext : null,
);
```

State is random, stored only as a hash, bound to both the connection and an opaque
host-supplied authorization context, expiring and atomically consumed once. Missing or
mismatched context rejects the callback without consuming state or changing tokens.
The package never reads `$_GET`/`$_SESSION`, emits a redirect or calls `exit`. Queue
jobs and backend API calls therefore either obtain a valid token, perform one bounded
refresh, wait for the shared database lock, or throw a typed OAuth exception; they
never start an interactive flow.

Access and refresh tokens are encrypted with the host `APP_KEY` and stored per named
connection. Callback exchange and refresh use the same database lock. After acquiring
it, refresh re-reads the token, and both paths use a token generation compare-and-set
so an expired lock owner cannot overwrite a newer rotation. New access/refresh tokens
are committed before releasing an owned lock. Set `lock_lease_seconds` strictly longer
than every OAuth connection's request `timeout`; invalid relationships fail before a
callback state is consumed. The table names and database connection are configurable
under `mautic.oauth`, or hosts may replace `OAuthTokenRepository`,
`OAuthStateRepository` and `OAuthRefreshLock`.

A rejected refresh token (`invalid_grant`) requires a new host-initiated authorization.
Invalid client credentials, throttling (including numeric `Retry-After`), remote 5xx,
network failure and malformed responses remain distinct, secret-free failures. These
typed failures survive the SDK boundary before any contact request is sent; genuine
post-send mutation transport failures retain their ambiguous outcome classification.
The token endpoint is called once per exchange/refresh attempt and is never
automatically retried.

If remote rotation succeeds but local persistence fails, or a stale lock owner loses
the token-generation compare-and-set, `OAuthTokenPersistenceUncertain` is raised and
the package does not retry the grant. Stop automated work for that connection, inspect
the durable token record without exposing secrets, and normally start a fresh
host-authorized browser flow. Also confirm the connection name, callback URL, runtime
secret and migration. The multi-process integration suite proves shared locking,
generation convergence and one-time state consumption with barrier-coordinated,
separate PHP processes over one file-backed SQLite database. This is not a memory fake.
Equivalent MariaDB runtime behavior has not been exercised by the local suite.

## Contact identity and field ownership

The package can persist an explicit mapping from an opaque host contact reference to a
Mautic contact ID. Publish the optional mapping migration separately when needed:

```bash
php artisan vendor:publish --tag=mautic-identity-migrations
php artisan migrate
```

The host reference has a stable `scope` and `key`. Include the tenant boundary and a
stable domain/morph alias in `scope`; the package never reads a current tenant, guard,
User model or Eloquent model class:

```php
use Trianity\LaravelMautic\Contracts\ContactMappingRepository;
use Trianity\LaravelMautic\Identity\LocalContactReference;

$reference = new LocalContactReference('tenant:acme:crm-contact', (string) $contact->id);
$mappings = app(ContactMappingRepository::class);
$mapping = $mappings->claim('main', $reference, $mauticContactId);
```

Database uniqueness enforces one remote ID per local reference and one local reference
per remote ID within each named connection. The same local reference may use a
different remote ID on another connection. Concurrent exact claims are idempotent;
crossed claims throw `ContactMappingConflict`. Mapping state changes and explicit
`relink()` recovery use a generation compare-and-set, so stale callers throw
`ContactMappingChanged`. Missing, deleted or merged contacts are recorded explicitly;
they are never recreated, merged or relinked automatically. Email is not identity, so
changing it does not change an established mapping.

Connection names, scopes and keys are byte-exact opaque values: case, accents and
leading/trailing whitespace are significant and stored unchanged. SHA-256 tuple hashes
provide database-independent lookup and uniqueness, while every match is verified
against the original values. Hosts that ran the earlier development mapping migration
must republish `mautic-identity-migrations` and run the explicit
`2026_10_19_011000_add_identity_hashes_to_mautic_contact_mappings` upgrade. It validates
and backfills without rewriting identities, and stops on incompatible data rather than
merging mappings. The upgrade is intentionally not reversible because restoring the
old collation-dependent unique indexes can collapse valid byte-distinct identities.
The package includes an opt-in disposable MariaDB test using
`MAUTIC_TEST_MARIADB_HOST`, `MAUTIC_TEST_MARIADB_DATABASE` (the name must contain
`test`), and optional port/username/password variables. SQLite success alone is not
evidence that a MariaDB environment passed.

Before an initial claim, exact-email discovery trims and ASCII-case-folds the query and
returned values, then returns an explicit none, unique or ambiguous result based on
distinct remote IDs. A unique result still requires a host-authorized claim; ambiguous
results remain available for review and are never silently selected:

```php
$result = $manager->connection('main')->contactDiscovery()
    ->exactEmail($contact->email);

$candidate = $result->uniqueContact(); // null for none or ambiguous
```

Field ownership is an explicit allowlist. `HostContactFields` accepts only host fields
listed by the caller, while each `FieldRule` maps one host alias to one Mautic alias as
host-owned, Mautic-owned or shared with an explicit host-wins/Mautic-wins decision:

```php
use Trianity\LaravelMautic\Fields\FieldMapper;
use Trianity\LaravelMautic\Fields\FieldOwnership;
use Trianity\LaravelMautic\Fields\FieldRule;
use Trianity\LaravelMautic\Fields\HostContactFields;
use Trianity\LaravelMautic\Fields\SharedFieldResolution;

$mapper = new FieldMapper(
    new FieldRule('first_name', 'firstname', FieldOwnership::Host),
    new FieldRule('company', 'company', FieldOwnership::Mautic),
    new FieldRule('phone', 'phone', FieldOwnership::Shared, SharedFieldResolution::HostWins),
);

$resolution = $mapper->resolve(
    HostContactFields::fromArray($explicitHostFields, ['first_name', 'company', 'phone']),
    $mauticContact,
);
```

Resolved values distinguish `unchanged`, explicit `clear` and `set`; unchanged values
are omitted from `outboundValues()` and `inboundValues()`. DNC, consent evidence,
tags and segment membership are rejected from generic field rules and remain separate
host workflows. The mapper never calls `Model::toArray()` and does not send changes.
Task 05 owns durable delivery. The schema and concurrency decision is documented in
[docs/decisions/001-contact-identity-storage.md](docs/decisions/001-contact-identity-storage.md).

## Transactional outgoing synchronization

Publish the optional outbox migration and run it on the same database connection that
owns the host transaction whose contact change is being recorded:

```bash
php artisan vendor:publish --tag=mautic-outbox-migrations
php artisan migrate
```

Record an explicit immutable command inside the host transaction. The operation ID is
a stable host idempotency key; revisions must increase for the same byte-exact
connection/local reference. Only explicitly allowlisted `ContactFields` are persisted:

```php
use Illuminate\Support\Facades\DB;
use Trianity\LaravelMautic\Identity\LocalContactReference;
use Trianity\LaravelMautic\Outbox\ContactOutbox;
use Trianity\LaravelMautic\Outbox\ContactSyncCommand;
use Trianity\LaravelMautic\Write\ContactFields;

DB::transaction(function () use ($contact): void {
    app(ContactOutbox::class)->record(ContactSyncCommand::update(
        operationId: (string) $contact->sync_operation_id,
        connection: 'main',
        localReference: new LocalContactReference('tenant:acme:crm-contact', (string) $contact->id),
        revision: $contact->sync_revision,
        fields: ContactFields::fromArray(['firstname' => $contact->first_name], ['firstname']),
        source: 'crm-contact-save',
    ));
});
```

The insert rolls back with the host transaction. Queue dispatch happens only after
commit and dispatch failure never removes the row or fails the already valid host
operation. Schedule `app(OutboxPoller::class)->recover()` at a bounded interval and run
the configured Laravel queue workers; duplicate jobs are harmless. The outbox database
connection must be the host transaction connection—atomicity across two databases is
not claimed.

Workers serialize by connection/contact using a shared database lease and deliver every
stored revision sequentially. A higher revision cannot discard partial changes or pass
an unresolved create, delayed retry, failure or ambiguity. Set
`mautic.outbox.lease_seconds` above `operation_budget_seconds`; the declared budget must
cover each individual request timeout. No transaction or row lock is held during HTTP.
This lease is not a Mautic-side fence: an expired in-flight operation becomes ambiguous
and blocks later revisions until explicit recovery. Exactly-once delivery and guaranteed
remote ordering are not claimed.

Transient safe failures use bounded local backoff. A valid numeric `Retry-After` is a
minimum server delay and may exceed the local `maximum_delay_seconds` cap. OAuth token
persistence uncertainty and reauthorization requirements durably block the named
connection. After resolving the token state externally, call
`OutboxOperator::resumeAuthentication()` with an actor and reason to resume and audit
delivery.

A create 5xx, transport uncertainty, or local persistence failure after remote success
is an `ambiguous` command and is never automatically repeated. The returned remote ID
is retained as recovery evidence when available, including when its first persistence
attempt fails but the subsequent ambiguity write succeeds. An operator must use
`OutboxOperator::reconcileCreated()` with the verified remote ID, or
`confirmCreateAbsentAndRetry()` only after proving that no remote contact was created.
For an ambiguous update, the host must first establish that the old worker and request
cannot mutate Mautic again; a remote read alone is not proof. It then passes that trusted
attestation explicitly to `confirmAmbiguousUpdateApplied()` or
`authorizeAmbiguousUpdateReplay()`. The first completes the barrier; the second returns
the same immutable revision to pending delivery. Both require the active mapping, and
all decisions are audited atomically with their state change. Failed rows are retained,
and `retryFailed()` cannot bypass ambiguity handling. Stored diagnostics contain
package-owned classifications rather than upstream messages or credentials.

SQLite and MariaDB behavior, crash boundaries and limitations are recorded in
[docs/decisions/002-outbox-delivery.md](docs/decisions/002-outbox-delivery.md).

Hosts that published an earlier development outbox migration must republish and run both
the `2026_10_19_021000_correct_mautic_contact_outbox` and
`2026_10_19_022000_reconcile_legacy_mautic_contact_outbox` migrations. Legacy
`superseded` rows are retained as `ambiguous`, never blindly replayed, whether or not a
newer revision completed. The forward migration also detects installations where the old
05A migration already made an identifiable lower historical revision pending and moves
it behind the same explicit reconciliation barrier. Completed commands remain completed.
These corrections are intentionally irreversible.

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
the requested local contact and verify the local-to-remote mapping. A remote ID, email
address or segment membership is not proof of identity or authorization.

## Explicit contact writes

```php
use Trianity\LaravelMautic\Write\ContactFields;

$writes = $manager->connection()->contactWrites();

$contact = $writes->create(ContactFields::fromArray(
    ['email' => 'person@example.com', 'firstname' => 'Ada'],
    allowed: ['email', 'firstname'],
));

$contact = $writes->update($contact->remoteId, ContactFields::fromArray(
    ['firstname' => 'Grace', 'company' => null],
    allowed: ['firstname', 'company'],
));

$writes->addToSegment($contact->remoteId, $segmentId);
$writes->removeFromSegment($contact->remoteId, $segmentId);
```

`ContactFields` accepts arrays only and rejects every key not present in the supplied
host allowlist. Generic writes also reject IDs, tags, DNC and server-managed timestamp
fields even if allowlisted. An omitted key is not sent. Explicit `null`, `false`, `0`
and `''` values are retained in the JSON payload; whether a blank value clears a
specific remote field still depends on that field and the target Mautic version.
Never pass `Model::toArray()` as a field map.

Updates always use SDK `edit(..., false)`, which is an HTTP PATCH. A missing target
throws `ContactUpdateTargetNotFound`; it is never recreated with PUT. Single and
batch creates perform an exact-email preflight when a non-empty email is present and
throw `ContactAlreadyExists` for a known match. This prevents known Mautic email
merges, but it is not an atomic uniqueness guarantee: a concurrent creator can race
the preflight. Durable identity, idempotency and reconciliation belong to later
milestones.

Batch writes return a `ContactBatch` whose `items` retain each original request index.
Each item is explicitly `successful`, `failed` or `unknown`, with a successful
`Contact`, or a normalized failure kind and optional HTTP status. The convenience
`contacts` property contains all successful contacts even when another item failed.
Mautic batches are not atomic: inspect every item, reconcile unknown outcomes and do
not retry the whole batch automatically after partial success.

Duplicate preflights use Mautic's structured `email eq` filter instead of search
text, page through the complete result, and compare the returned email value after
trimming surrounding whitespace and ASCII case-folding. Search result counts alone
never establish a duplicate, so a similar address or wildcard-like character such
as `+`, `%` or `_` does not block creation unless a returned email normalizes to the
same value. The structured SDK query transports the email as a parameter rather than
interpolating it into Mautic's search-command grammar.

Mutations are side-effectful and not automatically retried. A timeout or connection
loss during creation produces `AmbiguousMutationOutcome`: the remote contact may
exist, so reconcile before any retry. The package deliberately provides no
remote-delete or general DNC-removal API.

Segment commands call only Mautic's manual add/remove endpoints. They do not change
marketing consent, remove DNC, update contact fields or send mail. Filter-based
segment membership is evaluated asynchronously and may lag field changes. Manual
removal can create an exclusion even while the contact matches the filter; verify
exclusion/re-add behavior against the target Mautic version before automating it.

REST contact creation does not execute Mautic form submit actions such as campaign
entry, notifications, attribution or form-specific consent handling. Keep an existing
form submission path until each of those effects has been explicitly migrated.

`Fakes\FakeContactWriter` implements `Contracts\ContactWriter` for deterministic host
tests. No model observer, queue, durable outbox or automatic synchronization is added.

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
