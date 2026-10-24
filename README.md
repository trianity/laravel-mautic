# Laravel Mautic

[![CI](https://github.com/trianity/laravel-mautic/actions/workflows/ci.yml/badge.svg)](https://github.com/trianity/laravel-mautic/actions/workflows/ci.yml)

A model-independent Laravel integration built on the official `mautic/api-library`.
MIT licensed; intended for publication as `trianity/laravel-mautic` on Packagist.

**Status: 1.0.0.** Basic Auth and opt-in OAuth2
connections, normalized contact reads, explicit contact writes, manual segment
commands, explicit email-restriction commands, durable identity mappings, field ownership rules, transactional outgoing
synchronization, a verified webhook inbox and raw upstream contexts are implemented.
Bounded reconciliation and connection diagnostics are implemented. Compatibility
evidence is specific to the capabilities exercised against Mautic 7.2.1; it is not a
blanket claim for every Mautic feature, version or host integration.

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
See [CONTRIBUTING.md on GitHub](https://github.com/trianity/laravel-mautic/blob/main/CONTRIBUTING.md)
for Laravel 12 development.

## Install in a host application

After the 1.0.0 tag is published to Packagist, install the package with:

```bash
composer require trianity/laravel-mautic:^1.0
php artisan vendor:publish --tag=mautic-config
```

Before publication, source contributors can use the repository checkout described
above; consumer applications should not depend on `dev-main`. The service provider is
auto-discovered. Configure the **host application's** `.env`:

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

## Optional database migrations

No package migration runs implicitly. Publish only the subsystem used by the host, then
review and run the published migration with Laravel. Connection and table names remain
configurable in `config/mautic.php`; companion subsystems must use the same configured
database where the table below says so.

| Feature | Publication tag | Required companion migrations | Tables or purpose |
| --- | --- | --- | --- |
| OAuth2 token lifecycle | `mautic-oauth-migrations` | None | Tokens, authorization states and refresh locks |
| Contact identity | `mautic-identity-migrations` | None | Local-to-remote contact mappings |
| Transactional outbox | `mautic-outbox-migrations` | Identity for processing | Commands, aggregate locks, audits and connection blocks |
| Webhook inbox | `mautic-inbox-migrations` | Identity and a host projector for processing | Deliveries, events and contact locks |
| Reconciliation | `mautic-reconciliation-migrations` | Identity for comparison and default repair | Checkpoints, observations, findings and audits |
| Subscription delivery | `mautic-subscription-migrations` | Identity and outbox on the same database | Intents, segment associations, locks and audits |
| Every optional subsystem | `mautic-migrations` | Deliberately publishes all rows above | All six canonical migrations, exactly once |

The aggregate tag is a convenience for hosts deliberately installing every subsystem;
it is not a prerequisite for OAuth2 alone.

## OAuth2 authorization and token lifecycle

OAuth2 uses Mautic's authorization-code and refresh-token grants; this package does
not use or assume `client_credentials`. Publish and run the optional namespaced
storage migration before enabling an OAuth2 connection:

```bash
php artisan vendor:publish --tag=mautic-oauth-migrations
php artisan migrate
```

The 1.0.0 canonical migration includes the state `context_hash` and token `generation`
columns. The package does not publish compatibility migrations for pre-release schemas;
hosts must never edit a migration that has already run in a shared environment.

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
against the original values. The same connection hash is indexed before mapping
inventory ordering and limiting, so case-insensitive database collations cannot mix
names such as `main` and `Main`. The package publishes one canonical identity create
migration. No compatibility migration or automatic host-database reset is provided.
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
Durable delivery is the explicit outbox workflow below. Its schema and concurrency decision is documented in
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

Fresh installations use the complete canonical
`2026_10_19_020000_create_mautic_contact_outbox` schema, which creates the commands,
aggregate locks, operator audits and OAuth connection-block tables. This is the only
published outbox migration; Laravel's migration ledger handles its normal execution.

Pre-release development schemas are not an upgrade contract. The package never drops or
resets a host database automatically. Do not run
`migrate:fresh` against a host application database or a production database copy;
perform any scoped recreation only in a confirmed disposable development environment.

## Durable subscription delivery

Subscription delivery is an explicit host business action; saving an arbitrary model
never starts it. Publish the canonical subscription migration and keep its configured
database connection equal to the identity and contact-outbox connections:

```bash
php artisan vendor:publish --tag=mautic-subscription-migrations
php artisan migrate
```

After the host has authorized a free or paid subscription, save its contact and the
delivery intent in the same transaction. Build `ContactFields` only from the outbound
values produced by the explicit field-ownership mapping described above; never pass a
model array.

```php
use Illuminate\Support\Facades\DB;
use Trianity\LaravelMautic\Identity\LocalContactReference;
use Trianity\LaravelMautic\Segment\StaticSegmentDefinition;
use Trianity\LaravelMautic\Subscription\SegmentTarget;
use Trianity\LaravelMautic\Subscription\SubscriptionCommand;
use Trianity\LaravelMautic\Subscription\SubscriptionDelivery;
use Trianity\LaravelMautic\Write\ContactFields;

DB::transaction(function () use ($contact, $acceptedSubscription): void {
    $contact->save();

    app(SubscriptionDelivery::class)->record(new SubscriptionCommand(
        connection: 'main',
        localReference: new LocalContactReference('tenant:acme:contact', (string) $contact->id),
        eventKey: 'accepted-subscription:'.$acceptedSubscription->id,
        contactRevision: $contact->mautic_revision,
        contactFields: ContactFields::fromArray(
            ['email' => $contact->email, 'firstname' => $contact->first_name],
            ['email', 'firstname'],
        ),
        segment: SegmentTarget::createIfAbsent(
            'kaszanap-74-paid',
            new StaticSegmentDefinition('Kaszanap 74 paid', 'kaszanap-74-paid'),
        ),
        source: 'accepted-subscription',
    ));
});
```

The event key identifies one accepted business event. An identical replay returns its
existing delivery; conflicting reuse throws `SubscriptionConflict`. The segment key is
a stable connection-scoped host key, not a display name. Use `SegmentTarget::existing()`
with a verified remote ID when creation is forbidden, or `createIfAbsent()` with an
explicit published empty-filter definition. Existing targets are inspected but never
silently edited or converted from dynamic to static.

Run the normal contact outbox worker/poller and the subscription job queue, and schedule
`SubscriptionPoller::recover()` for lost dispatches. Inspect `SubscriptionRecord` for
pending, retry-wait, completed, failed, blocked or ambiguous state. A membership GET
failure before POST retains the completed contact and segment prerequisites. Transient
authentication, timeout, throttling, server and transport failures use a durable
`next_attempt_at` delay, honor numeric `Retry-After`, and stop after
`subscription.max_membership_attempts` (default 5); polling and queue redelivery cannot
bypass that time. A non-retryable or exhausted preflight read is explicitly resumable
without uncertain-write attestation because no POST was sent.

`SubscriptionOperator` can
resume a repaired contact prerequisite, confirm an observed membership, resume a definite
membership-write failure, authorize replay only with explicit prior-request attestation,
or associate a separately verified segment after an ambiguous create. Operator recovery is
audited with actor, reason, prior state and outcome and derives connection/segment identity
from the stored intent. It never blindly replays an uncertain create or membership.

The worker waits for the durable contact revision and active mapping, provisions and
persists the segment association under shared connection/key provisioning state, then adds
and reads back membership. A durable marker precedes create; expiry or worker loss leaves a
key-level blocker that a fresh event, restart or poller cannot bypass. Known create IDs are
retained before association for verified recovery. It does not release DNC, renew consent, submit a form, start a campaign or
send mail. Segment membership and campaign execution are separate Mautic behaviors.
Mautic supplies no conditional segment-create primitive, so the package coordinates its
own workers but cannot fence unrelated external creators. The local flow is resumable,
not remotely atomic or exactly once. See
[Decision 006](docs/decisions/006-subscription-delivery.md).

The opt-in `composer test:mariadb-subscription-burst` acceptance runs two 30-contact,
four-process scenarios against only the configured disposable MariaDB test database: an
owned static segment already exists, or one missing segment is coordinated and created.
This is a modest concurrency correctness test, not a production latency/capacity claim and
not a Mautic campaign or email-delivery test. An association to a segment later deleted in
Mautic remains blocked; create-if-absent applies only to initial provisioning and does not
recreate a deleted associated business resource.

The real-Mautic counterpart is separately gated from every other live suite:

```bash
MAUTIC_OAUTH_FIXTURE_TESTS=1 MAUTIC_OAUTH_SUBSCRIPTION_BURST_TESTS=1 composer test:oauth-subscription-burst
```

It additionally requires the established OAuth fixture gates/disposable acknowledgement
and accepts `MAUTIC_OAUTH_SUBSCRIPTION_BURST_INTERVAL_SECONDS=60..120`. It creates and
cleans only cryptographically named owned resources. Four workers are ready before the
first arrival, remain alive across temporarily empty queues and process throughout the
arrival window. Parent and workers atomically reserve every API/OAuth attempt from one
shared 500-request operation allowance before transport; cleanup has an independent
100-request allowance and starts only after every worker is reaped. One parent monotonic
clock records arrival, completion, drain and cleanup durations. Do not enable it on
production.

## Verified webhook inbox

Webhook ingress is opt-in and the package registers no route. Publish the inbox
migration, add a connection-specific runtime secret name and explicitly allow only the
events the host is ready to process:

```bash
php artisan vendor:publish --tag=mautic-inbox-migrations
php artisan migrate
```

```php
// config/mautic.php
'inbox' => [
    'maximum_body_bytes' => 1_048_576,
    'lease_seconds' => 30,
],

'connections' => [
    'main' => [
        // ...
        'webhook' => [
            'secret_env' => 'MAUTIC_WEBHOOK_SECRET',
            'allowed_events' => [
                'mautic.lead_post_save_update',
                'mautic.lead_post_delete',
            ],
        ],
    ],
],
```

The default secret provider reads the named environment variable at runtime; the secret
value does not enter published configuration or Laravel's configuration cache. Mautic
7.2 documents `Webhook-Signature` as base64-encoded HMAC-SHA256 over the exact raw
request body. Pass that unmodified body and header to `WebhookInbox::receive()` and
return a successful response only after it returns:

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Trianity\LaravelMautic\Inbox\WebhookInbox;

Route::post('/integrations/mautic/{connection}/webhook', function (
    string $connection,
    Request $request,
    WebhookInbox $inbox,
) {
    $receipt = $inbox->receive(
        $connection,
        $request->getContent(),
        $request->headers->get('Webhook-Signature'),
    );

    return response()->json(['receipt_id' => $receipt->receiptId], 202);
});
```

Map `WebhookIngressException` to a non-success response: authentication failures should
not be processed, malformed/oversized bodies are client errors, and persistence failure
must be retryable by the sender. If the host enables CSRF protection for web routes,
exclude only the exact webhook URI pattern (for example
`integrations/mautic/*/webhook`), not the integration namespace or API routes broadly.
Signature verification is the authentication boundary for this public endpoint; do not
add browser redirects or session authentication.

The delivery and each contained event commit before queue dispatch. Event payloads are
encrypted with the host `APP_KEY`; metadata contains hashes and package-owned status
codes, and the package does not log customer payloads. Dispatch is only a wake-up: use
`WebhookInboxPoller::recover()` to redispatch pending or expired processing rows after a
queue outage or process crash. Database leases serialize events for one connection and
remote contact. The host must bind `Contracts\InboundContactProjector` to provide the
current allowlisted host snapshot, its `FieldMapper`, and the explicit application of
inbound changes. Mautic-owned fields may flow inward; host-owned fields and unchanged
values do not, so generic inbound handling does not echo an outbox write.

Only contact update and delete events are eligible for generic processing. Unknown,
unmapped and disallowed events remain inspectable as ignored. Channel subscription,
consent and DNC changes are never treated as generic contact fields. Deletes update only
the existing connection-scoped mapping state; they do not delete a host contact.

Mautic's documented payload has event-type arrays and may batch multiple types and
items. It does not document a delivery UUID, and not every event is guaranteed to carry
an event UUID or timestamp. Consequently, every authenticated delivery receives its own
receipt and repeated byte-identical deliveries are preserved. An explicit `eventId` or
`event_id`, when supplied, is a separate semantic identity and can be deduplicated.
Otherwise change detection provides idempotency. A strictly older timestamp is ignored
after a newer event for the same mapped contact has completed; equal or absent
timestamps are processed normally.

The signature contains no timestamp, so it proves authenticity but not freshness. A
captured signed body can be replayed and will be durably recorded. The package makes no
exactly-once claim and performs no automatic retention deletion; hosts must choose and
operate a retention policy appropriate to their audit and privacy requirements. See
[decision 003](docs/decisions/003-webhook-inbox.md) for the persistence and crash model.
The opt-in `MariaDbWebhookInboxTest` exercises the published final schema, byte-exact
semantic hashes under a case-insensitive collation and the database-backed shared contact
lease on the disposable MariaDB configured by `MAUTIC_TEST_MARIADB_*`. It is real shared
storage evidence, but not a separate-process inbox stress test.

## Reconciliation and diagnostics

Publish the canonical reconciliation schema separately. It creates current checkpoints,
ID-only observations, finding codes and operator audits; it does not store contact fields,
emails, segment lists, DNC data or webhook payloads:

```bash
php artisan vendor:publish --tag=mautic-reconciliation-migrations
php artisan migrate
```

Advance at most the configured number of stable ID-ordered pages per scheduler run:

```bash
php artisan mautic:reconcile main
php artisan mautic:diagnose main
```

The first request captures the current maximum remote contact ID. Pages use `id > cursor`
and `id <= high water`, ordered by ID. Every eligible request is atomically reserved in
the shared database before HTTP, with an owner, generation and bounded expiry. The
reservation records request accounting and the next allowed request time before the
network call; result commits require the same generation and owner. Expired reservations
can be recovered, while delayed success/failure and pre-restart results are rejected. A
successful conditional reservation is read back only through its exact scan, generation,
owner and expiry. If replacement happens before that handoff finishes, the old invocation
receives no owned work and sends no request under the replacement worker's reservation.
`request_reservation_seconds` must exceed every configured request timeout.

Observation rows and the cursor commit together.
A failed request retains its cursor, so a retry overlaps the uncommitted page and unique
scan/remote-ID keys suppress duplicate observations. Contacts created above the high
water wait for the next explicit scan. `page_size`, `maximum_pages_per_run` and
`minimum_page_interval_seconds` bound API load. Numeric `Retry-After` from Mautic or a
transient OAuth token request is a minimum even when it exceeds the local interval.
Token-persistence uncertainty and reauthorization block the scan instead of becoming an
ordinary retry. Start another scan only deliberately:

```bash
php artisan mautic:reconcile main --restart
```

Remote scans record unmapped contacts and remote contacts whose mapping is already
non-active. They never infer deletion from absence, a failed request or an incomplete
scan, and never replace segment membership with an empty list. SDK list totals are
retained: contradictory watermark results and empty/no-progress pages with reported
remaining rows fail, while short pages remain resumable until the response proves its
requested range exhausted.

Stale active mappings use a separate bounded recovery command. It performs authoritative
contact reads and treats only a normalized 404 as missing. Dry-run is the default:

```bash
php artisan mautic:reconcile-mappings main --limit=50
```

After independent confirmation, applying requires all three explicit inputs:

```bash
php artisan mautic:reconcile-mappings main --limit=50 \
    --apply-missing --actor=operator-id --reason=confirmed_404
```

The default implementation requires `identity.database_connection` and
`reconciliation.database_connection` to be identical. The expected active mapping is
changed to `missing` and its audit is inserted atomically. It does not delete, recreate
or relink a contact. Use `--after=<remote-id>` for the next bounded mapping page.

Diagnostics return state counts, current scan status/finding counts and OAuth token
health without decrypting tokens or returning contact data, local identity values,
command/event payloads, credentials or upstream messages. The package performs no
automatic retention deletion; hosts own the observation/finding/audit retention policy.
See [decision 004](docs/decisions/004-reconciliation.md) for the crash model and
[the host migration guide](docs/HOST_MIGRATION.md) before replacing existing Form posts.

## Source-only opt-in live Mautic proof

This section applies only to a
[GitHub source checkout](https://github.com/trianity/laravel-mautic); test code and its
PHPUnit configurations are intentionally absent from the Composer distribution.
`tests/Live` is excluded from `composer test` and `composer check`. The dedicated command
also skips before networking unless `MAUTIC_LIVE_TESTS=1` is set. It accepts only a
loopback Mautic URL, requires the exact disposable acknowledgement, verifies the expected
installed `mautic/core-lib` version from a local installation path and creates random
contacts under a required `lm-live-*` fixture prefix:

```bash
export MAUTIC_LIVE_TESTS=1
export MAUTIC_LIVE_DISPOSABLE=I_UNDERSTAND_THIS_DELETES_OWN_FIXTURES
export MAUTIC_LIVE_URL=http://127.0.0.1:8080
export MAUTIC_LIVE_USERNAME=dedicated-api-user
export MAUTIC_LIVE_PASSWORD=replace-me
export MAUTIC_LIVE_FIXTURE_PREFIX=lm-live-local
export MAUTIC_LIVE_INSTALL_PATH=/absolute/path/to/local/mautic
export MAUTIC_LIVE_SERVER_VERSION=7.1.1
composer test:live
```

The core probe verifies Basic Auth, create, partial PATCH, DNC reads and cleanup after
re-reading the exact fixture email. `MAUTIC_LIVE_STATIC_SEGMENT_ID` additionally verifies
manual add/read/remove. Dynamic segment convergence requires a preconfigured disposable
rule plus `MAUTIC_LIVE_DYNAMIC_SEGMENT_ID`, `MAUTIC_LIVE_DYNAMIC_FIELD` and
`MAUTIC_LIVE_DYNAMIC_VALUE`; its wait is bounded.

Webhook proof requires a local receiver configured in Mautic to append JSONL records with
`body_base64` and `signature` to `MAUTIC_LIVE_WEBHOOK_CAPTURE_FILE`, plus
`MAUTIC_LIVE_WEBHOOK_SECRET`. After signature verification it structurally requires an
exact contact-update event, fixture email, contact ID and changed field value; ID
substrings, create events, unrelated nested IDs and incomplete trailing JSONL records do
not count. OAuth refresh has a separate
`MAUTIC_LIVE_OAUTH_TESTS=1` gate and requires dedicated client credentials, refresh token
and an absolute `MAUTIC_LIVE_OAUTH_ROTATED_TOKEN_FILE`. Before refresh, the harness
exclusively creates that file, restricts and verifies mode `0600`, and keeps its handle.
After rotation it writes the complete token JSON and requires the exact byte count plus
flush/fsync success; otherwise it raises token-persistence uncertainty without retrying.
Precreation prevents avoidable preparation loss but cannot remove the crash window after
remote rotation and before durable local persistence. Missing optional setup produces a
skip, not compatibility evidence. Never point this suite at production or shared contacts.

### Isolated local OAuth connection acceptance

The read-only OAuth acceptance suite is separate from the mutation-oriented live suite
and from `composer check`. It accepts only the exact `https://mautic.test` origin after
exclusive loopback resolution, requires Mautic 7.2.1 installation metadata and never
falls back to the Basic Auth username or password. Its callback is a test-only loopback
listener; the package service provider still registers no OAuth route.

Create a dedicated Mautic OAuth2 client with Authorization Code and refresh-token grants
and this exact redirect URI:

```text
http://127.0.0.1:8765/oauth/callback
```

Add the following to ignored `tests/.env`; never commit the actual client secret:

```dotenv
MAUTIC_OAUTH_ACCEPTANCE_TESTS=1
MAUTIC_LIVE_URL=https://mautic.test
MAUTIC_LIVE_INSTALL_PATH=/absolute/path/to/local/mautic
MAUTIC_LIVE_SERVER_VERSION=7.2.1
MAUTIC_LIVE_CA_BUNDLE=/etc/ssl/certs/mautic.test.crt
MAUTIC_LIVE_OAUTH_CLIENT_ID=replace-with-dedicated-client-id
MAUTIC_LIVE_OAUTH_CLIENT_SECRET=replace-with-dedicated-client-secret
MAUTIC_LIVE_OAUTH_REDIRECT_URI=http://127.0.0.1:8765/oauth/callback
```

`MAUTIC_LIVE_CA_BUNDLE` is a trust anchor used with certificate and hostname verification
enabled; it is not a `verify=false` bypass. Load the ignored file explicitly because the
Pest bootstrap does not parse dotenv files:

```bash
set -a
source tests/.env
set +a
php tests/Support/oauth_acceptance_prepare.php
```

Start the callback listener in a second terminal, then open the authorization URL printed
by the prepare command and authorize with the dedicated API user:

```bash
set -a
source tests/.env
set +a
php tests/Support/oauth_acceptance_callback.php
```

The state is expiring, stored only as a hash and bound to an opaque context kept in
mode-0600 ignored storage. The callback process exchanges the code through the package's
`OAuth2Service` and persists encrypted tokens in a dedicated SQLite database. It never
prints the code or tokens. After the callback succeeds, run:

```bash
composer test:oauth-live
```

The suite starts in a new PHP process, proves persisted-token loading, performs a bounded
API v1 collection read, rejects an isolated invalid bearer token, performs exactly one
controlled refresh in the dedicated repository, reads again and separately probes the
documented API v2 contact collection. Optional existing fixture reads require both
`MAUTIC_LIVE_OAUTH_FIXTURE_CONTACT_ID` and `MAUTIC_LIVE_OAUTH_FIXTURE_EMAIL`; without a
dedicated fixture they skip. The suite never creates, updates or deletes contacts and
does not change DNC, consent or segment membership.

The OAuth contact-lifecycle suite is selected separately and requires both its own
mutation opt-in and the exact disposable acknowledgement. The read-only OAuth opt-in
alone never authorizes mutations:

```bash
set -a
source tests/.env
set +a
export MAUTIC_OAUTH_FIXTURE_TESTS=1
export MAUTIC_LIVE_DISPOSABLE=I_UNDERSTAND_THIS_DELETES_OWN_FIXTURES
composer test:oauth-fixture
```

It creates a cryptographically suffixed `lm-live-mautic721.*@example.test` contact,
records ignored mode-0600 ownership evidence, verifies create/read/discovery/PATCH/
clear/DNC/duplicate behavior and deletes only after an exact ID-and-email re-read.
Configure `MAUTIC_LIVE_OAUTH_STATIC_SEGMENT_ID` only for a dedicated static test
segment; otherwise that probe is explicitly skipped. Never select a business or
dynamic segment for this suite.

The focused Task 07F membership probe makes that segment mandatory and runs without
the unrelated contact-lifecycle case:

```bash
composer test:oauth-segment
```

Prepare the segment manually in the local Mautic UI before setting the ignored ID:

1. Open `https://mautic.test/s/segments/new` as the dedicated test operator.
2. Set **Name** exactly to `lm-live-mautic721-static`.
3. Set **Visible to other users** to **Yes** so the dedicated OAuth principal can
   access it, and keep **Available in Preference Center** disabled.
4. Do not add any filter. Save the published segment with an empty **Filters** list.
5. Copy only its numeric ID into ignored `tests/.env` as
   `MAUTIC_LIVE_OAUTH_STATIC_SEGMENT_ID=<id>`.

The probe reads the configured definition before creating a contact and requires the
exact ID, exact name and an explicitly empty API `filters` array. Visibility alone is
not treated as evidence that a segment is static. Without the configured ID it fails
before OAuth configuration or mutation. A test-only PSR-18 decorator measures every
forwarded API or OAuth token `sendRequest()` attempt against one shared 16-request
operation limit. Cleanup then switches the same budget object to an independent
5-request allowance, so operation exhaustion cannot consume or replenish cleanup
capacity. Rejected requests never reach the verified TLS client, and neither request
headers nor bodies are inspected or logged by the budget.

The custom-field lifecycle probe is separately selected and requires a dedicated
published Contact/Text field with a non-empty label and Required and Unique identifier
disabled. Put its actual lowercase alias in ignored `tests/.env`:

```dotenv
MAUTIC_LIVE_OAUTH_CUSTOM_FIELD_ALIAS=lm_oauth_text
```

For alias `test_field`, the corresponding Mautic email token is
`{contactfield=test_field}`. The token renders the stored field value; it does not grant
write permission or change the package allowlist.

With the same fixture opt-in and disposable acknowledgement, run:

```bash
composer test:oauth-custom-field
```

The command validates list and item metadata before contact mutation, never creates or
changes the shared field definition, and uses the same transport-level operation/cleanup
budgets. It proves create/read/update, unrelated-PATCH preservation, explicit clear, DNC
and segment-set preservation, then ownership-checked contact deletion.

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

Custom fields use their real aliases in the same field map. Generic writes require every
alias to be explicit; omitted aliases are unchanged, while null/empty-string clear intent
enables Mautic's adapter-owned blank-overwrite control:

```php
use Trianity\LaravelMautic\Write\ContactFields;

$custom = $contact->field('host_reference');
$connection->contactWrites()->update($contact->remoteId, ContactFields::fromArray(
    ['host_reference' => 'host-42'],
    ['host_reference'],
));
```

Each DNC entry exposes optional `remoteId`, `reason`, `dateAdded`, `channelId` and
`comments`, plus mandatory `channel`. `knownReason()` maps only verified Mautic constants
to `DoNotContactReason`; unknown raw values remain available. `bounceEvidence()` returns
`SubtypeUnavailable` for reason 2 because the supported contact API exposes no reliable
hard/soft subtype or history. Missing/null DNC data is never converted into an empty,
contactable result.

Manual email blocks and explicit channel-level release are separate from generic
contact fields:

```php
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Restriction\ManualEmailBlock;

$connection = $manager->connection('main');
$block = ManualEmailBlock::record(
    explanation: 'Requested by the support operator under ticket 1842.',
    clock: app(Clock::class),
);

$blocked = $connection->restrictionWrites()->blockEmail($mauticContactId, $block);
$inspected = $connection->contactReads()->find($mauticContactId);
$released = $connection->restrictionWrites()->releaseEmailRestriction($mauticContactId);
```

The host supplies the non-empty human explanation. `ManualEmailBlock::record()` captures
one UTC timestamp and appends a marker such as
`[trianity/laravel-mautic; recorded_at=2026-10-23T15:30:00Z]`; reusing that object retains
the same explanation and timestamp. The marker is diagnostic, not ownership evidence or
a remote idempotency key. Mautic may sanitize comments—7.2.1 encoded submitted line feeds
as `&#10;`—so `submittedCommentsObserved` reports byte-exact observation separately from
the resulting restriction outcome. A retained pre-existing restriction means the request
succeeded but the verified email state did not become the submitted manual block.

`releaseEmailRestriction()` targets the contact and email channel. It does not select a
host-owned row or require an expected reason/version, and it may affect an unsubscribe,
bounce or administrative restriction. `Released`, `RestrictionRemains` and
`NoMatchingRestriction` describe the returned and, when needed, explicitly re-read state;
successful release never establishes consent or permission to send. Missing `dateAdded`
remains unavailable. These immediate commands are not automatic host-model
synchronization, durable delivery or exactly-once processing. See
[Decision 005](docs/decisions/005-host-owned-administrative-restrictions.md).

An independently configured manual restriction can be checked without mutation by
loading ignored `tests/.env`, setting `MAUTIC_OAUTH_RESTRICTION_READ_TESTS=1` and running
`composer test:oauth-restriction-read`. This suite has no cleanup or write path. The
tested Mautic 7.2.1 fixture omits the optional DNC `dateAdded` property advertised by
installed entity metadata; the corrected acceptance passed while preserving that state
as Missing instead of inventing a timestamp. This observation is scoped to that version,
endpoint and fixture. See the
[sanitized compatibility report](docs/compatibility/mautic-7.2.1-oauth-manual-restriction-read.md).

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
host allowlist. Generic writes also reject IDs, tags, DNC, server-managed timestamps
and the adapter-owned `overwriteWithBlank` control even if allowlisted. An omitted key
is not sent. Explicit `null`, `false`, `0` and `''` values are retained in the JSON
payload. For updates containing `null` or `''`, the `/api` adapter adds Mautic's
`overwriteWithBlank=true` control so the explicit blank is not silently ignored.
Whether a blank is valid for a specific field still depends on that field and the
target Mautic version.
Never pass `Model::toArray()` as a field map.

Updates always use SDK `edit(..., false)`, which is an HTTP PATCH. A missing target
throws `ContactUpdateTargetNotFound`; it is never recreated with PUT. Single and
batch creates perform an exact-email preflight when a non-empty email is present and
throw `ContactAlreadyExists` for a known match. This prevents known Mautic email
merges, but it is not an atomic uniqueness guarantee: a concurrent creator can race
the preflight. Use the durable identity, outbox and reconciliation mechanisms described
above for those separate guarantees and recovery paths.

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
exist, so reconcile before any retry. Restriction mutations use the same conservative
rule: a potentially applied command is not automatically replayed. The package
deliberately provides no remote-delete or generic multi-channel DNC API.

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
Do not call raw mutations in a critical checkout path. Read the architectural boundary in
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

The durable identity is `(connection, local contact reference, remote contact ID)`.
Email is a discovery attribute, not a permanent identity or proof of ownership.
Subscriber-facing disclosure of segment membership requires host-side authorization
and verified identity. The package must never expose arbitrary email lookups publicly.

## License

[MIT](LICENSE.md). Dependencies retain their own licenses. This package does not
vendor or copy the Mautic SDK or the SWIS wrapper.
