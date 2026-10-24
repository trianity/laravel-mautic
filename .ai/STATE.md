# Current state

## Implemented foundation

- Composer metadata and MIT license, PHP 8.4+, Laravel 12/13 constraints.
- Lazy named connections with Basic Auth via the official API library.
- Validated configuration, finite request/connect timeouts, TLS verification,
  no redirects, no automatic retry, replaceable transport/connection factories.
- Provider auto-discovery, publishable config, optional facade and raw SDK contexts.
- Immutable normalized contact DTOs, bounded search and contact segment memberships.
- Typed DNC metadata and reason reads with explicit unavailable bounce-subtype evidence.
- Explicit typed manual email blocks and accepted channel-level restriction release.
- Typed published static-segment inspection, bounded exact-alias discovery and explicit
  empty-filter segment creation.
- Normalized read failures, read-operation safety metadata and deterministic read fake.
- Allowlisted contact creates, PATCH-only partial updates and checked batch writes.
- Manual segment membership commands, mutation safety metadata and deterministic fake.
- Opt-in OAuth2 authorization-code and refresh-token lifecycle with encrypted durable
  per-connection versioned tokens, host-context-bound one-time callback state and
  database-shared callback/refresh locking.
- Model-independent, connection-scoped contact identity mappings, exact-email
  discovery and explicit host/Mautic/shared field ownership resolution.
- Transactional outgoing contact outbox with after-commit dispatch, recovery polling,
  sequential aggregate revisions, conservative lease-expiry recovery, connection-level
  OAuth blockers and audited ambiguous create/update recovery.
- Durable accepted-subscription delivery with transactionally captured contact intent,
  stable replay identity, connection/key segment associations, process-coordinated
  provisioning and read-verified membership that preserves DNC.
- Opt-in verified webhook inbox with connection-specific raw-body HMAC authentication,
  encrypted durable events, asynchronous recovery, shared contact leases and explicit
  host-owned inbound projection through mapping and field-ownership policies.
- Bounded per-connection reconciliation with durable checkpoints, scan watermarks,
  keyset pagination, shared request reservations, replay-safe observations/findings and
  explicit mapping recovery.
- Privacy-safe connection diagnostics and separately configured, opt-in live-server
  acceptance suites that are excluded from the default test command.
- Pest unit/feature tests, Testbench, Pint, Larastan level 8, CI matrix.
- Ordered implementation prompts and architecture/release/contribution guidance.

## Not implemented

Generic consent workflows and blanket live-Mautic compatibility are not implemented.
The retained acceptance evidence is scoped to the documented capabilities exercised
against a validated disposable Mautic 7.2.1 target; it does not verify host business
integration, campaign configuration or mail execution. No webhook route is automatically exposed.
Remote deletion and generic multi-channel DNC operations are not exposed. No migration runs implicitly,
no automatic database reset or model event exists, and no compatibility bridge for
unreleased development schemas is provided. Version 1.0.0 is published; no 1.1 tag or
Packagist publication exists.

Independent host-owned restrictions, durable restriction-command delivery and
conditional release remain deferred. The implemented stock endpoint deliberately does
not promise ownership or an atomic revision check; Decision 005 records this accepted
scope and the stronger possible future design.

## Verification

## 1.1.0 reusable Laravel OAuth infrastructure — 2026-10-24

Task 10 prepares an opt-in package browser OAuth layer while preserving all 1.0 host-owned
routes. Runtime secrets resolve first through Laravel's environment repository and then the
process environment; custom provider bindings remain authoritative. Package routes are
disabled by default and, when enabled, require host middleware, a server-side connection
allowlist and a host `OAuthBrowserAuthorizer` binding whose default denies access.

`OAuthCallbackUrlResolver` supplies the same explicit-or-derived URI to authorization, code
exchange and `mautic:oauth:callback-url`. Derivation uses configured `app.url`, including its
base path, never a request Host header. Browser state retains the existing connection-bound,
expiring, single-use database state plus a random connection-scoped session context. The
controller returns only fixed sanitized outcomes and delegates all token work to
`OAuth2Service`. No migration or persisted representation changed.

Focused OAuth infrastructure coverage passed 13 tests / 51 assertions. Final
`composer check` passed strict Composer validation, Pint, Larastan level 8 and 334 tests /
1613 assertions with seven opt-in tests skipped. `git diff --check` passed.

A prospective Composer archive built from an isolated temporary Git snapshot contains the
new controller, authorizer contract/default, callback resolver, command, credential-health
enum, published configuration and adoption documentation. Existing export exclusions still
remove tests, AI/IDE material, audit reports and development-only tool configuration. The
temporary Git process emitted non-fatal stream-fd warnings in the restricted sandbox; the
archive completed and every expected inclusion/exclusion check passed. No MariaDB or live
Mautic suite ran, and no host file was changed.

## 1.0.0 provider registration refactor — 2026-10-24

Task 10 candidate A-01 is complete. `MauticServiceProvider::register()` delegates in its
original order to private default-contract, identity, OAuth, outbox, subscription, inbox,
reconciliation and manager registration methods. Binding statements, `bindIf` versus
`singletonIf` versus `singleton` choices, closures, singleton lifetimes, lazy resolution,
configuration reads and boot behavior are unchanged. New characterization tests prove
that pre-registered host overrides and their chosen singleton/transient lifetimes survive
package registration, and that representative defaults remain lazy with their declared
lifetimes. Candidate A-02, subscription row hydration extraction, is deferred.

Focused provider and subsystem verification passed 111 tests / 788 assertions. A clean
prospective archive installed without development dependencies in a disposable Laravel
13.38.0 consumer; provider registration/boot, lazy binding lifetimes and the Composer
discovery declaration passed. The sandbox printed three non-fatal stream-fd warnings
during consumer boot, but all assertions ran and the process exited zero. Final
`composer check` passed Composer validation, Pint, Larastan level 8 and 321 tests / 1565
assertions with seven opt-in tests skipped. `git diff --check` passed. No MariaDB or live
Mautic suite ran.

## 1.0.0 production-source refactor review — 2026-10-24

Task 10 reviewed all 190 production PHP files at
`99d74bb95713b9db08c9605be588d15bf603e1a9` without changing runtime code, tests,
configuration, migrations or public APIs. The ranked report at
`docs/audits/1.0.0-src-refactor.md` recommends only two bounded pre-release candidates:
private subsystem registration methods in `MauticServiceProvider`, followed optionally by
a private `DatabaseSubscriptionStore` row mapper after adding corruption characterization.
OAuth settings consolidation, persistence-store decomposition and subscription phase
collaborators are deferred. Independent durable state machines, operation-specific retry
and ambiguity handling, response distinctions and conditional SQL remain intentional.
No correctness defect was identified. `composer check` passed Composer validation, Pint,
Larastan level 8 and 319 tests / 1552 assertions with seven opt-in tests skipped;
`git diff --check` passed. No MariaDB or live Mautic suite ran.

## 1.0.0 release cleanup — 2026-10-24

Task 09 prepares the first-release candidate without tagging or publishing it. The raw
Mautic 7.2.1 OpenAPI export moved, checksum unchanged, to the intentionally ignored local
`.idea/references/mautic/7.2.1/api-v2/openapi.json`; tracked capability and provenance
documentation remains sufficient without that optional development artifact. Git archive
rules exclude AI material, source-only tests and test configurations while retaining the
consumer documentation, runtime source, configuration and all canonical migrations.

OAuth now has the exact `mautic-oauth-migrations` publication tag in addition to the
unchanged six-migration aggregate and existing subsystem tags. Composer declares
`ext-json`, and the README distinguishes minimal OAuth setup, optional subsystem
dependencies and source-only live acceptance tooling. Detailed cleanup verification is
recorded in `docs/audits/1.0.0-readiness.md`.

## Continuous-arrival live burst correction — 2026-10-23

Task 07J-C corrects only the OAuth burst harness. Four workers now pass an explicit ready
barrier before the first event, continuously discover eligible durable work, survive empty
intervals and stop after producer completion plus 30 completed deliveries. Harness state and
an atomically reserved 500-request operation budget share a small coordination SQLite file
separate from delivery storage; cleanup has an independent 100-request budget and cannot
overlap active workers. One parent monotonic clock observes all reported durations.

Offline lifecycle/budget regressions passed, including deadline failure, orderly drain,
pre-transport rejection, failed-attempt accounting, restart-safe limits, parent/worker
sharing and cleanup isolation. The final Mautic 7.2.1 run passed 2 tests / 94 assertions in
138.62 seconds. Existing used 216 operation attempts (parent 37; workers 47/48/42/42), a
60.017-second arrival window and 0.544-second drain. Missing used 217 (37; 48/48/42/42),
60.013 seconds and 0.570 seconds. Both used 93 cleanup attempts, completed and verified all
30 memberships, preserved DNC, proved pre-final-arrival completion and contention, and
deleted every owned fixture with not-found confirmation. The earlier 152.35-second Task
07J-B run remains historical accumulated-backlog evidence, not continuous-arrival evidence.
The separately opted-in MariaDB burst regression passed 2 tests / 77 assertions. Final
`composer check` passed 318 tests / 1525 assertions with seven opt-in skips.

## Membership failure phases and 30-subscription burst — 2026-10-23

Task 07J-B separates the initial membership GET, mutation dispatch and verification GET.
Transient preflight reads now use a durable bounded retry wait with server `Retry-After`;
permanent/exhausted reads remain safely recoverable at the completed segment prerequisite.
Only response-proved mutation rejection can resume without attestation. Server, malformed,
unexpected and transport-ambiguous mutation results, plus post-write verification failures,
retain the existing no-replay uncertainty contract and permit exact read-only confirmation.

The public delivery path now has two reproducible 30-contact scenarios driven by four PHP
processes, actual outbox/subscription processors, a deterministic provisioning barrier and
recovery polling. Existing targets dispatch zero creates; missing create-if-absent targets
dispatch one. SQLite passed 2 tests / 57 assertions and the opt-in disposable MariaDB run
passed 2 tests / 72 assertions. A non-empty DNC fixture remained unchanged. This is bounded
correctness evidence, not campaign, email, latency or production-capacity evidence. The
separately gated disposable Mautic 7.2.1 burst passed both scenarios with 2 tests / 128
assertions in 152.35 seconds. Each scenario scheduled 30 arrivals over 60 seconds, used four
workers, completed and remotely verified all 30 memberships, and retained the known DNC.
The existing target dispatched zero creates and the missing target exactly one. Each used
217 operation and 93 ownership-checked cleanup attempts; all owned contacts and segments
were deleted and confirmed absent. Final `composer check` before the live run passed
Composer validation, Pint, Larastan level 8 and 313 tests / 1495 assertions with seven
opt-in tests skipped.

## Subscription concurrency and recovery correction — 2026-10-23

Task 07J-A replaces status-only subscription claims with exact owner/generation/expiry
reservations and turns segment provisioning into durable connection/key state. A create
in-flight marker precedes the HTTP request; expiry, process loss or uncertain completion
blocks every event for that key until verified operator recovery. Returned segment IDs are
captured before association and retained through response-validation, first-evidence-write
or association failure where storage remains available.

Operator recovery now verifies repaired contact prerequisites, observed membership or an
exact remote segment before changing state. Uncertain replay and segment-create resolution
require explicit prior-request attestation where the old request could still complete.
Every supported recovery stores bounded actor/reason, prior state and outcome without contact
data. The canonical subscription migration adds claim generations, durable provisioning
state and recovery audits; no development upgrade bridge was added.

Offline focused coverage passed 24 tests / 165 assertions, including fault injection and
genuine multi-process SQLite tests driving the actual processor across create lease expiry
and immediate process loss after the durable marker. The
separately configured MariaDB schema/transaction test passed 1 test / 11 assertions; it is
not a MariaDB multi-process race. Final `composer check` passed Pint, Larastan level 8 and
305 tests / 1375 assertions with five opt-in MariaDB tests skipped in the default run.

The corrected OAuth Mautic 7.2.1 acceptance passed 1 test / 16 assertions with 18 operation
and six cleanup attempts. A public contact operation established the mapping, a non-empty
manual email restriction was installed before subscription, both durable subscription events
completed, and exact restriction metadata remained unchanged. Ownership cleanup deleted the
run-owned contact and segment and confirmed not-found. No host-application integration ran.

## Durable subscription delivery — 2026-10-23

Task 07J adds the concrete accepted-subscription flow without model observers or host
business rules. The public command uses a named connection, byte-exact local reference,
stable event key, positive contact revision, explicitly allowlisted contact fields and
a stable host segment key. Identical event replay is idempotent and conflicting reuse is
rejected. Capture requires the host transaction and the same configured database
connection as identity and contact outbox storage.

Typed segment operations inspect remote ID, exact alias, publication, visibility and
filters, and create only an explicit published empty-filter definition. Subscription
workers wait for the existing contact outbox and active mapping, coordinate provisioning
through a shared database lease, persist the remote association, then add and read back
membership. Definite failures, blockers and ambiguous outcomes remain distinct. No path
releases DNC, restores consent, submits forms, enters campaigns or sends mail.

Offline real-SDK and Testbench coverage includes canonical schema publication,
rollback, after-commit dispatch recovery, stable replay/conflict, connection/key
isolation, existing and created static targets, membership verification, and a real
two-process SQLite provisioning race proving one coordinated create attempt. The OAuth
subscription suite is separately gated by `MAUTIC_OAUTH_SUBSCRIPTION_TESTS=1`, the
existing fixture gates and disposable acknowledgement. The final Mautic 7.2.1 run
passed 1 test / 14 assertions with 15 operation attempts and six cleanup attempts. It
completed a new contact into the prepared static segment, exact replay, a second event
for the mapped contact, creation and use of an owned missing segment, and unchanged DNC.
Exact ownership checks preceded cleanup; the owned contact and created segment were
deleted and API not-found was confirmed. Sanitized mode-0600 evidence records only a
run hash, outcomes and counts.

## General verification policy

See the dated entries below. Default tests exercise the real SDK with fake HTTP and do
not contact a Mautic installation. Live-server checks have their own configuration and
require explicit target-specific opt-in.

## Next task

Review the 1.1 diff and remote PHP/Laravel CI matrix before any authorized commit, tag or
publication. Host adoption must use a released package followed by host acceptance; this
package task does not modify the host.

## OAuth restriction-write acceptance — 2026-10-23

Task 07I adds a dedicated typed email-restriction writer over the stock SDK. A manual
block always uses reason 3, requires a human explanation and appends one stable UTC
`recorded_at` marker captured when the command is constructed. Explicit release uses the
contact-and-email-channel endpoint. Both operations inspect the returned DNC state and
perform at most one bounded GET fallback when the mutation's embedded contact shape
cannot establish it. They expose retained, absent, released and remaining outcomes;
verification uncertainty after a potentially completed write is distinct and no
side-effectful request is automatically replayed.

The separately gated OAuth suite passed 1 test / 49 assertions against Mautic 7.2.1.
Four isolated disposable contacts used independent 16-request operation budgets and
three-request cleanup reserves. The clean scenario used 14 operation requests: manual
reason 3 was present, repeating the same command retained it, the first channel release
reported released and the second reported no matching restriction. The existing-manual
scenario used seven operation requests and retained its original metadata. The bounce
scenario used ten operation requests; reason 2 became manual and explicit release left
the channel clear. The unsubscribe scenario used ten operation requests; reason 1
survived the attempted manual replacement, then explicit channel release removed it.
That administrative release was not treated as renewed consent.

Mautic sanitized submitted comment line feeds to `&#10;`, so neither clean nor bounce
storage was reported as byte-exact even though the explanation and marker remained
observable in the sanitized representation. The mutation response used an embedded
contact shape that required the single explicit GET fallback. All four cleanup paths
performed exactly three requests, verified exact current ID and generated email, deleted
only the owned contact and confirmed `ContactNotFound`; no fixture remained unresolved.
Sanitized mode-0600 evidence contains no contact IDs, addresses or raw comments. See
`docs/compatibility/mautic-7.2.1-oauth-restriction-writes.md`.

Offline real-SDK coverage includes endpoint and payload shape, stable command reuse,
invalid pre-network inputs, manual/unsubscribe/unknown outcomes, missing/null/malformed
DNC state, the bounded fallback read, missing `dateAdded`, normalized failures, ambiguous
transport without replay, and release/no-match/remaining results. Decision 005 now accepts
the stock channel-level semantics while retaining independent ownership as a deferred
design, not a plugin prerequisite. Final `composer check` passed strict Composer
validation, Pint, Larastan level 8 and 277 tests / 1184 assertions with four explicitly
opt-in MariaDB tests skipped. `git diff --check` also passed; remote CI was not run for
the uncommitted worktree.

## Restriction-write contact identity correction — 2026-10-23

Task 07I-A binds every accepted restriction state to the exact requested contact ID.
An otherwise usable embedded mutation contact with a different ID is now rejected as
verification evidence and follows the existing single GET fallback without replaying the
block or release. If that fallback returns another contact, the writer throws
`RestrictionStateNotVerified` for the original mutation and connection with
`mutationMayHaveCompleted = true`; it issues no second GET and no repeated mutation.

Real-SDK regressions cover both public operations with synthetic IDs and exact request
history. They prove one POST plus one GET for mismatched embedded evidence, one POST plus
one GET followed by conservative failure for a mismatched fallback, and retain the
existing no-fallback behavior for a usable response belonging to the requested contact.
The focused restriction-write selection passed 26 tests / 98 assertions, and Larastan
level 8 reported no errors. Final `composer check` passed strict Composer validation,
Pint, Larastan level 8 and 281 tests / 1210 assertions with four explicitly opt-in
MariaDB tests skipped. `git diff --check` passed. This controlled malformed-response
correction did not run a new live acceptance suite; the existing Mautic 7.2.1 evidence
above remains unchanged. Remote CI was not run for this uncommitted worktree.

## OAuth manual-restriction read acceptance — 2026-10-23

Task 07H adds a separately selected, strictly read-only OAuth acceptance suite for an
independently prepared persistent manual email restriction. Fixture configuration is
validated before OAuth construction, and a shared PSR-18 budget allows four operation
attempts with no cleanup reserve. The suite performs exactly two public typed contact
reads and contains no contact, DNC, field, tag, segment, consent, subscription, mail or
deletion mutation path.

The first live run failed because the acceptance expectation incorrectly required the
optional `dateAdded` property. Its three-attempt, token-refreshed, zero-mutation evidence
remains preserved separately. Both observed contact responses for this Mautic 7.2.1
endpoint and fixture omitted `dateAdded`, despite installed entity metadata advertising
it. This scoped observation is not generalized to all Mautic responses.

The corrected live run passed 1 test / 6 assertions with two contact GETs, no token
refresh and zero mutation requests. Contact ID/email, the exact typed DNC ID, channel
`email`, manual reason 3, explicit-null channel ID, exact comments, Missing date presence
and narrow per-entry `BounceEvidence::NotBounced` all matched across both reads. No
database fallback or fabricated timestamp was added, and the persistent fixture was not
changed.

Offline coverage includes pre-network gates, selection among multiple restrictions,
missing/duplicate IDs, explicit null versus missing, Unicode comments, Missing/Null/value
date states, value-only RFC3339 validation, changed repeated presence/value, zero cleanup
allowance and separate sanitized mode-0600 evidence. See
`docs/compatibility/mautic-7.2.1-oauth-manual-restriction-read.md`. Public runtime code
did not change, so the changelog was not updated. The final focused selection passed 27
tests / 69 assertions. `composer check` passed with Larastan level 8; the default suite
passed 255 tests / 1112 assertions with four opt-in MariaDB tests skipped.

## Custom fields and delivery restrictions — 2026-10-23

Task 07G extends normalized DNC entries with optional record ID, date added, channel ID
and comments while preserving missing, null and malformed states. Verified Mautic reasons
map to `DoNotContactReason`; unknown raw values remain intact. A reason-2 restriction
returns `BounceEvidence::SubtypeUnavailable` because the existing contact REST response
does not expose the parser subtype or bounce history. No free-text inference is used.

Offline real-SDK tests cover actual custom aliases, explicit set/clear PATCH payloads,
omitted unrelated aliases, complete/empty/missing/malformed DNC shapes, known and unknown
reasons, and unavailable bounce subtype. A focused `composer test:oauth-custom-field`
suite validates a configurable dedicated Contact/Text field definition before creating a
cryptographically named contact. It shares the transport-level 16-request operation and
five-request cleanup budgets and retains exact ID/email ownership cleanup.

The focused suite first skipped without mutation opt-in and proved that a missing alias
fails before OAuth configuration or networking. After the dedicated `test_field` alias
was configured, the authorized Mautic 7.2.1 run passed 1 test / 17 assertions. It proved
metadata preflight, create/read/update, preservation through an unrelated PATCH, explicit
clear, unchanged known DNC and membership sets, and exact ownership cleanup. Sanitized
evidence records `deleted`, 14 operation attempts and three cleanup attempts. No email was
sent and the shared field definition was not mutated.

Installed Mautic 7.2.1 source proves DNC response fields/reason constants, alias-scoped
PATCH behavior, channel-level DNC replacement/removal semantics, and internal bounce
details that are not exposed by the contact API. This Task 07G snapshot preceded the
Task 07I decision to accept explicit stock channel-level operations while deferring
independent ownership and atomic conditional release.

Local `composer check` passed strict Composer validation, Pint, Larastan level 8 and
232 tests / 1065 assertions; four explicitly opt-in MariaDB tests skipped. The final
focused read/write/preflight selection passed 42 tests / 125 assertions, and
`git diff --check` passed. No remote CI was run for this uncommitted worktree.

## Installed Mautic API v2 reference — 2026-10-19

Task 07C captured the installed local Mautic 7.2.1 API v2 contract, now retained as the
optional ignored local reference `.idea/references/mautic/7.2.1/api-v2/openapi.json`,
using API Platform's official
`api:openapi:export --spec-version=3` console command. The installed packages are
`mautic/core-lib` 7.2.1 at Composer source revision
`4a0fbebb76ac1bc84fb507227f21e103f7436d42` and `api-platform/core` 4.4.3 at
`206a0ffc60afa7289eaf3ab73dcb1248897ff4d2`; the distribution has no application Git
metadata. PHP 8.5.11 performed the export.

The generated document is OpenAPI 3.2.0 with 68 paths, 196 operations and 475 component
schemas. It parsed successfully; all 456 unique references are local and resolved. Its
SHA-256 is `f1a44c2f72b1764cbdb47712e3dde0b276a22afcd8475798955bca7e67a481b4`.
A scan found no credential, bearer-token, cookie, private-key, local-origin or
email-address-shaped value. The adjacent README records provenance, configuration
dependencies, attribution and validation; `capabilities.md` records the evidence limits.

The contract documents contact ID reads, creates and JSON merge-patch updates, including
nullable scalar write fields. It documents page-number collection pagination and JSON-LD
totals plus common error representations. It does not document exact-email filtering,
ID range filters, ordering/keyset traversal, page-size control, contact segment
membership reads or changes, contact DNC/subscription representations, or OAuth/Bearer
security schemes. Segment and channel CRUD are not treated as membership or subscription
parity. These gaps leave API v2 adoption and live compatibility acceptance undecided.

No runtime package code, authentication, migrations, outbox, inbox or reconciliation
behavior changed. No live suite, HTTP compatibility request or contact mutation ran, and
no business data was written. `MAUTIC_LIVE_USERNAME` is the canonical live-harness
variable, and the operator has aligned the ignored local `tests/.env` without exposing
its value.

Local `composer check` passed strict Composer validation, Pint, Larastan level 8 and
192 tests / 962 assertions; four opt-in MariaDB tests were skipped by the default
environment. Remote CI was not run.

## Local OAuth connection acceptance — 2026-10-20

Task 07D adds a separately selected, read-only OAuth Authorization Code acceptance
harness for the exact `https://mautic.test` origin. It is outside `composer check` and the
mutation-oriented live suite, requires `MAUTIC_OAUTH_ACCEPTANCE_TESTS=1`, accepts only
exclusive loopback DNS results and never treats Basic Auth variables as OAuth fallback.
The test-only callback binds `127.0.0.1:8765`; the package provider still registers no
route.

Preflight confirmed Mautic 7.2.1, PHP 8.5.11, ignored local credentials and passing
non-development Composer platform requirements for both the package and installed
Mautic. The system trust store rejects the installation's self-signed certificate. Full
certificate and hostname verification succeeds when the installed
`/etc/ssl/certs/mautic.test.crt` is supplied as the explicit local trust anchor; TLS
verification and redirect restrictions remain enabled.

The harness uses the package's `OAuth2Service`, expiring context-bound database state,
encrypted database token repository, database refresh lock, managed OAuth authentication
and existing `/api` adapter. Ignored mode-0600 storage survives the callback process so
the acceptance suite can prove new-process token loading. Its bounded probes cover the
API v1 contact collection, isolated rejected-token behavior, one controlled refresh and
post-refresh read, plus a separate Bearer-only API v2 contact collection request. Fixture
ID/email, segment and DNC reads skip unless a dedicated existing test contact is supplied.

Focused offline guard/harness tests passed with 8 tests / 19 assertions. The isolated
suite without its dedicated opt-in skipped 2 tests / 0 assertions and made no request.
A dedicated OAuth2 client then completed browser authorization and code exchange through
the package service. The callback process persisted the encrypted token and the separate
Pest process loaded it. The bounded API v1 read passed; an isolated invalid Bearer request
returned HTTP 401 without redirect; one controlled refresh advanced the dedicated token
generation from 1 to 3 (expiry simulation plus rotated-token persistence), left a future
expiry and supported a post-refresh read. The separate Bearer-only API v2 collection
probe returned HTTP 200 `application/json` without redirect.

The live response established that Mautic 7.2.1 reports collection `total` through the
upstream SDK as a numeric string. A focused regression test was red before the shared
response boundary safely normalized non-negative integer strings for contact reads,
discovery, duplicate preflight and reconciliation; normalized DTO totals remain integers.
`composer test:oauth-live` passed 1 test / 19 assertions and skipped the fixture-specific
test because no dedicated contact ID/email was configured. Therefore ID, exact-email,
segment-membership and DNC evidence remains pending. No contact, segment, DNC, consent or
other business mutation ran. The sanitized report is
`docs/compatibility/mautic-7.2.1-oauth-acceptance.md`.

Local `composer check` passed strict Composer validation, Pint, Larastan level 8 and
200 tests / 981 assertions; four opt-in MariaDB tests were skipped by the default
environment. Remote CI was not run.

## OAuth contact lifecycle acceptance — 2026-10-23

Task 07E adds a third, separately selected suite for owned OAuth contact mutations.
`composer test:oauth-fixture` requires `MAUTIC_OAUTH_FIXTURE_TESTS=1`, the existing
read-only OAuth configuration and the exact disposable acknowledgement. It retains the
exact-origin, loopback-only DNS, verified TLS, no-redirect and OAuth-only guards. The
read-only opt-in by itself skips before configuration or networking.

Each run creates a cryptographically suffixed `lm-live-mautic721.*@example.test`
fixture and maintains ignored mode-0600 evidence with the run ID, exact email, remote ID
and cleanup state. Cleanup first performs a typed read and requires the exact ID/email;
only then does it use the raw deletion escape hatch, followed by a typed
`ContactNotFound` assertion. Ambiguous creates are neither repeated nor blindly deleted.

The controlled refresh assertion now captures its baseline immediately before expiry
simulation. Offline tests cover both a valid initial token and an initially expired
token that an earlier use refreshes; the controlled procedure must add exactly two
generations. The read-only live suite passed 1 test / 19 assertions with the corrected
baseline, with its old externally configured fixture read still skipped.

The lifecycle probe found that Mautic 7.2.1 reports success but retains contact blanks
unless `overwriteWithBlank=true` accompanies the PATCH. Offline regression tests were
red before the `/api` writer added this adapter-owned control for update payloads that
contain null or empty-string values, including batch items. `ContactFields` now rejects
host attempts to provide the control. The final live suite passed 1 test / 34 assertions:
absence discovery, create, ID read, normalized exact-email discovery, firstname-only
PATCH preservation, explicit phone clear, known-empty DNC preservation, duplicate-create
rejection and ownership-checked cleanup all passed. Every contact created by diagnostic
and final runs has ignored evidence status `deleted`.

No dedicated static segment ID was configured, so only that optional probe remains
unverified. Populated-DNC preservation, dynamic segments, consent, campaigns, segment
definitions, outbox/inbox integration, reconciliation and full host acceptance remain
pending. The sanitized report is
`docs/compatibility/mautic-7.2.1-oauth-contact-lifecycle.md`. The exercised environment
used package baseline `280d94a8e18ce0b73cfd4a89d6fd096969aa2b4f` plus this Task 07E
worktree, PHP 8.5.12, Laravel 13.37.0, Testbench 11.3.0, Pest 5.3.3, API Library 4.0.0
and Mautic 7.2.1.

Final local `composer check` passed strict Composer validation, Pint, Larastan level 8
and 205 tests / 995 assertions; four opt-in MariaDB tests were skipped. Both isolated
OAuth suites skipped with zero assertions and no network access without their own
opt-ins. Remote CI was not run.

## OAuth static segment membership acceptance — 2026-10-23

Task 07F adds a focused `composer test:oauth-segment` probe inside the separately
gated OAuth fixture suite. It requires the existing fixture mutation opt-in and exact
disposable acknowledgement, and additionally requires
`MAUTIC_LIVE_OAUTH_STATIC_SEGMENT_ID` before constructing OAuth configuration or sending
any mutation. The configured local Mautic 7.2.1 segment passed the dedicated fixture
preflight.

Installed Mautic 7.2.1 source and functional tests show that `GET /api/segments/{id}`
returns the definition under `list`, including its `filters` array. The test-only
preflight therefore requires the exact configured ID, the exact dedicated name
`lm-live-mautic721-static`, and an explicitly empty `filters` array. It rejects missing,
inaccessible, malformed, differently named and dynamically filtered definitions;
`isGlobal` is not treated as static-segment evidence. No production API was expanded.

The live probe has a test-only PSR-18 decorator around the existing verified local HTTP
client factory. Every API or OAuth token `sendRequest()` attempt consumes one shared
16-request operation budget before forwarding, including transport failures and SDK
extra requests. Once cleanup begins the same budget object switches permanently to an
independent 5-request allowance; it neither resets nor replenishes operation work. The
probe creates only a random owned contact after segment preflight, treats membership
reads as ID sets, verifies add/repeated-add/remove/repeated-remove outcomes, compares DNC
before and after, records a possible membership before sending add, and preserves the
original failure through membership and exact-ownership contact cleanup.

Focused offline preflight coverage passed 7 tests / 10 assertions. The focused suite
without its mutation opt-in skipped 1 test / 0 assertions without networking. With the
mutation gates enabled but no configured segment ID, it failed before OAuth setup or
mutation with the explicit missing-prerequisite error. Task 07F-A added 4 request-budget
regressions / 22 assertions; the combined focused offline selection passed 21 tests / 58
assertions. It proves pre-forward rejection, shared factory accounting, transport-failure
accounting, independent cleanup capacity, secret-free errors and real SDK create-preflight
plus OAuth token endpoint wiring. The full `composer check` passed: Larastan level 8
reported no errors and the default suite passed 216 tests / 1027 assertions with 4
explicitly opt-in MariaDB tests skipped.

The Task 07F functional run passed, but its reported 15-request count was a manual
operation estimate and was not transport-level evidence. Task 07F-A removed that counter
and reran the authorized probe against exact `https://mautic.test`; it passed 1 test / 12
assertions with 15 measured operation HTTP attempts and 3 separately measured cleanup
attempts. Mautic accepted repeated add and repeated remove as successful commands, every
read showed the exact expected membership ID set immediately, and the known DNC value
remained unchanged. The run-owned contact was verified, deleted and confirmed absent;
cleanup evidence reports the membership already absent. No segment definition, DNC,
consent or customer data changed. Manual setup and sanitized evidence are recorded in
README and the compatibility report at
`docs/compatibility/mautic-7.2.1-oauth-static-segment.md`. No run-owned contact or
membership remains after Task 07F; the dedicated test segment remains unchanged.

## Reconciliation, diagnostics and live-server harness — 2026-10-19

Task 07 adds bounded, resumable contact reconciliation without treating an incomplete or
failed remote listing as deletion evidence. Each byte-exact connection has a durable
checkpoint with a captured remote-ID high-water mark. Pages use ascending keyset
pagination inside that fixed range; observations and findings are written atomically with
the compare-and-set cursor. Replaying a page is deduplicated by scan and remote contact.
Every watermark/page request first acquires a durable owner/generation reservation and
persists accounting plus the next-request time. Page/run limits and server-directed delay
additionally bound scheduled work. Restarting creates a new scan without changing
mappings by itself.

Reconciliation records only remote IDs, mapping references, state classifications and
sanitized failures. It reports unmapped remote contacts and non-active mappings, while
failed reads retain the cursor and never infer missing contacts or empty memberships.
Diagnostics summarize mapping states, outbox blockers, inbox failures, reconciliation
findings and OAuth token health without decrypting stored payloads or revealing tokens or
full contact data.

Mapping recovery is dry-run by default and scans active mappings in bounded stable pages.
Only an explicit remote `ContactNotFound` result is eligible for `--apply-missing`;
application requires an actor and reason. The active-to-missing transition and operator
audit are one database transaction guarded by the observed mapping generation. The
identity and reconciliation stores must use the same database connection for that atomic
repair.

The package publishes one canonical reconciliation migration containing checkpoints,
observations, findings and recovery audits. It has no schema-version detection, upgrade
bridge or automatic database reset. Existing disposable development schemas must be
recreated deliberately by their operator. The default suite remains network-free.

`phpunit.live.xml` and `composer test:live` provide a separately selected acceptance
harness. It requires exact opt-in, a loopback disposable target, an acknowledged test
environment, a dedicated `lm-live-*` fixture prefix and the expected installed Mautic version.
Its cases cover Basic Auth, contact create/PATCH/read, DNC, manual segments, optional
dynamic-segment convergence, optional webhook capture/signature evidence, and separately
opted-in OAuth refresh. Cleanup targets only contacts created by that run. Before refresh,
the harness exclusively creates the rotated-token output and verifies mode 0600.

Focused SQLite reconciliation tests passed with 7 tests / 106 assertions. The dedicated
opt-in MariaDB reconciliation test passed against the supplied disposable shared database
with 1 test / 10 assertions, exercising the canonical schema and recovery transaction.
Invoking the isolated live suite without its opt-in variables produced
4 skipped tests / 0 assertions and made no network request; this verifies gating only.
No live HTTP acceptance test was authorized or executed, so no Mautic server version is
yet compatibility-verified. At that earlier inspection, the adjacent local installation
metadata reported Mautic core 7.1.1, but inspecting that metadata was not runtime
compatibility evidence. Remote CI has not
been rerun for these uncommitted changes. Local `composer check` passed strict Composer
validation, Pint, Larastan level 8 and 168 tests / 797 assertions, with the four opt-in
MariaDB tests skipped by the default environment.

## Reconciliation correctness correction — 2026-10-19

Task 07A replaces post-request scheduling with an atomic shared-database reservation
before every watermark or page HTTP call. The reservation has a random owner, monotonically
increasing checkpoint generation, expiry and precommitted request accounting. Result
updates require the same scan, generation and owner. Expired work is recoverable without
holding a transaction across HTTP; delayed success, delayed failure and pre-restart
results cannot overwrite the newer checkpoint. The reservation lifetime is validated as
longer than every configured Mautic request timeout.

Current-response numeric `Retry-After` now crosses the SDK boundary for both watermark
and page failures and extends the durable schedule without a local cap. Malformed or stale
headers are ignored. Transient OAuth token failures preserve applicable delay, while token
persistence uncertainty, reauthorization and non-transient token failures durably block
the scan instead of becoming ordinary retries.

SDK reconciliation pages retain the filtered range total. Positive totals with empty
results, totals smaller than returned rows, invalid bounds/order, duplicates and
no-progress responses fail without moving the committed cursor. A short page remains
running when its total reports more rows; only consistent exhaustion completes it.

Mapping inventory now filters on the canonical byte-exact connection hash before
remote-ID ordering, one-row look-ahead and limiting. SQLite and the disposable
case-insensitive MariaDB test both distinguish `main` from `Main` with overlapping remote
IDs and audit exactly the intended application. The unreleased identity schema is one
canonical create migration; the prior development upgrade bridge was removed. Existing
disposable development schemas must be recreated manually, never through an automatic
host database reset.

The live OAuth harness exclusively creates, restricts and verifies its output file before
refresh. It persists complete rotated token JSON only when the exact byte count and
flush/fsync succeed; post-rotation failure raises the secret-free uncertain-persistence
exception. This reduces avoidable loss but cannot close the crash window between remote
rotation and durable local persistence. The live webhook harness now verifies the raw
signature and structurally matches the exact update event, fixture ID/email and changed
field while ignoring partial trailing JSONL records.

Controlled integration tests use separate PHP processes over one shared SQLite file. They
prove that simultaneous first invocations send one eligible request, an abandoned
reservation is recovered, request count/spacing remain durable, and the old worker's late
success and failure are rejected. This is real multi-process shared-storage evidence, not
an in-memory fake. The focused correction suite passed 39 tests / 332 assertions.

Local `composer check` passed strict Composer validation, Pint, Larastan level 8 and
189 tests / 918 assertions; four opt-in MariaDB tests were skipped by the default
environment. The configured disposable case-insensitive MariaDB identity and
reconciliation suites passed together with 2 tests / 22 assertions. The isolated live
suite remained gated with 4 skipped tests / 0 assertions and made no HTTP request. No
live Mautic compatibility run or remote PHP/Laravel CI matrix was executed.

## Reconciliation reservation handoff correction — 2026-10-19

Task 07B closes the update-to-readback ownership substitution window. A successful
conditional reservation update is now read back only with its exact connection hash,
scan ID, newly incremented generation, random owner and expiry. If expiry recovery or an
explicit restart replaces the reservation before readback, the old invocation receives
no owned reservation. `ContactReconciler` may return the current checkpoint for reporting,
but it does not issue HTTP or commit a result under the replacement worker's ownership.
The replacement row is neither released nor modified. This provides local attribution;
it does not remotely fence a request after a later reservation expiry.

The deterministic regression uses separate PHP processes sharing one persistent
file-backed SQLite database. A database query listener pauses worker A after its
successful reservation UPDATE and before readback. The shared clock then expires A,
worker B takes ownership (or restarts the scan and reserves it), and A resumes. Both
success and typed-failure HTTP paths are covered: A sends no request and cannot commit
either outcome, while B's complete ownership tuple remains intact and B completes.
This is real shared-storage process concurrency, not an in-memory fake.

The focused reconciliation/concurrency run passed 25 tests / 251 assertions. Local
`composer check` passed strict Composer validation, Pint, Larastan level 8 and
192 tests / 962 assertions; four opt-in MariaDB tests were skipped by the default environment.
The configured disposable MariaDB reconciliation test then passed with 1 test / 18
assertions. No live HTTP test or remote PHP/Laravel CI matrix was executed.

## Verified webhook inbox and inbound sync — 2026-10-19

Task 06 adds opt-in `WebhookInbox::receive()` without registering a route. It verifies
Mautic 7.2's documented `Webhook-Signature` as base64 HMAC-SHA256 over the exact bounded
raw body using a connection-scoped runtime secret, then validates the event-type/list
payload and atomically stores a delivery plus encrypted event rows before queue dispatch.
Missing or invalid signatures, invalid JSON, malformed batches, oversized bodies and
persistence failures are typed separately. The package logs no customer payload.

Each authenticated request receives a distinct receipt because Mautic does not document
a delivery UUID. Identical legitimate deliveries are retained. Optional explicit
`eventId`/`event_id` values are separate semantic identities; payload hashes are evidence,
not deduplication keys. The signature contains no timestamp and therefore provides no
freshness guarantee. No exactly-once claim or automatic retention deletion is made.

Pending and expired processing rows are recoverable by a bounded poller. Database-shared
leases serialize processing per byte-exact connection and remote contact. Strictly older
timestamped events are stale only after a newer mapped event completed; equal or missing
timestamps proceed through current-value comparison. A crash after host application but
before inbox completion can replay once the lease expires, and unchanged-field detection
turns that retry into a no-op.

Allowed contact updates resolve the existing connection-scoped mapping and pass the
remote contact through a host-bound `InboundContactProjector` and `FieldMapper`. Only
changed inbound Mautic-owned/shared-winner fields are applied; host-owned values do not
echo. Allowed deletes mark mapping state without deleting a host contact. Unknown,
unmapped and disallowed events are retained as ignored. Channel subscription, consent
and DNC events are never generic field updates.

Focused SQLite coverage validates signatures, bounds, invalid JSON, encrypted multi-type
batches, persistence rollback, repeated delivery preservation, explicit semantic
duplicates, stale ordering, connection isolation, consent exclusion, expired event
recovery, shared contact lease deferral, mapping deletion and absence of an automatic
route. Local `composer check` passed Composer validation, Pint, Larastan level 8 and
160 tests / 690 assertions; the three opt-in MariaDB tests were skipped in that default
run. The dedicated disposable MariaDB inbox test then passed the published final schema,
byte-exact semantic hashes under a case-insensitive collation and the database-backed
shared contact lease with 1 test / 9 assertions. This proves the lock contract against a
real shared store, but is not a separate-process inbox stress test. No live Mautic endpoint
was called, and remote CI was not rerun because no authorized push occurred.

## Webhook route test correction — 2026-10-19

Task 06A corrects a cross-version test assumption without changing runtime behavior.
Laravel 12/Testbench 10 provides two framework routes in this package test application,
so asserting that the entire application had zero routes failed even though
`MauticServiceProvider` registered none. The delete-event test now checks only its mapping
state responsibility. A separate isolated Testbench test starts without package providers,
registers a representative named host route, snapshots its methods, URI, name, action and
middleware, then registers and boots `MauticServiceProvider` and requires the complete
route definitions to remain identical. An automatic package webhook route would therefore
fail the test.

Focused webhook and provider tests passed with 10 tests / 58 assertions. Local
`composer check` passed Composer validation, Pint, Larastan level 8 and 161 tests / 691
assertions, with three opt-in MariaDB tests skipped by the default environment. These
checks ran on PHP 8.5.11, Laravel 13.37.0, Testbench 11.3.0 and Pest 5.3.3. The unchanged
PHP 8.4/8.5 and Laravel 12/13 remote CI matrix has not been rerun because no authorized
push occurred; all four matrix combinations therefore await remote verification.

## Durable outgoing synchronization — 2026-10-19

Implemented an optional transactional contact outbox for explicit allowlisted create
and update commands. The host supplies a stable operation ID, byte-exact local identity,
monotonic revision and source. Recording participates in the configured host database
transaction; rollback removes the command. Queue dispatch occurs after commit and is a
best-effort wake-up only. A bounded poller recovers committed dispatch gaps, so host
signup/order success does not depend on queue or Mautic availability after commit.

Database leases serialize workers per connection/contact without holding a transaction
across HTTP. Workers reload under the lease, ignore duplicate jobs, suppress stale
revisions and require the lease to exceed every configured Mautic request timeout.
Authentication, throttling, server and safe idempotent update transport failures use
bounded deterministic backoff. Numeric `Retry-After` now crosses the real SDK boundary.
Validation, missing targets, malformed responses and exhausted attempts remain retained
failures with only sanitized classifications and package-owned reason codes.

Creates are never blindly repeated after transport uncertainty or an expired processing
lease without a durable mapping. They become inspectable `ambiguous` rows. An operator
must claim a verified remote ID or explicitly confirm remote absence before retry; both
paths, plus failed-command retry, write actor/reason audit rows. No generic sync resets
consent, clears DNC, sends mail, deletes contacts or serializes host models.

The migration is explicitly publishable under `mautic-outbox-migrations`. The schema,
transaction boundary, retry rules and crash model are recorded in
`docs/decisions/002-outbox-delivery.md`. Focused tests execute the published migration
and cover rollback, dispatch failure/recovery, operation idempotency, conflicting
revisions, duplicate jobs, stale revisions, connection isolation, safe create, terminal
validation, Retry-After, operator audit and remote-success/local-crash reconciliation.
The concurrency integration test starts two barrier-coordinated PHP processes over one
shared file-backed SQLite database and proves that only one remote update is attempted;
it is not a memory fake.

Local `composer check` passed with strict Composer validation, Pint, Larastan level 8,
127 passing tests / 514 assertions and two opt-in MariaDB tests skipped by the default
environment. Both skipped tests were then run explicitly against the supplied disposable
case-insensitive MariaDB database: contact identity passed with 4 assertions, and the
published outbox migration/shared lease contract passed with 6 assertions. No live
Mautic or production endpoint was called. Remote PHP/Laravel CI was not rerun because no
authorized push occurred.

## Durable outgoing synchronization correction — 2026-10-19

Tasks 05A, 05B and 05C finalize strict per-aggregate sequential delivery. Every lower
revision must complete before a higher revision starts, preserving disjoint partial
changes and create-before-update prerequisites. Failed, delayed, blocked and ambiguous
commands stop the sequence until their documented operator recovery succeeds.

Lease expiry is no longer treated as proof that a remote request stopped. Any expired
in-flight operation becomes ambiguous and blocks later revisions. The configured lease
must exceed a declared complete-operation budget, but Mautic has no remote fencing or
conditional-write primitive, so exactly-once delivery and guaranteed remote ordering
are not claimed. Two controlled PHP processes sharing one SQLite file prove that a
newer revision is not sent while an expired older worker may still finish remotely.

Create 5xx and other mutation uncertainty are retained for reconciliation. A returned
remote ID is persisted before mapping and retained through mapping/local persistence
failures when storage is available. Read-only preflight failures remain safely retryable.
OAuth token-persistence uncertainty and reauthorization requirements durably block the
connection until an audited `resumeAuthentication()` after external resolution.
Transient token endpoint failures retain status and Retry-After; valid server delays may
exceed the local backoff cap.

The installed SDK adapter now reads response headers only when the current result proves
an HTTP response exists. First-request transport failures retain typed exceptions, and
headers from a previous response cannot supply stale retry metadata.

The package publishes one canonical 020000 migration containing the complete commands,
aggregate locks, operator audits and OAuth connection-block schema. Its rollback drops
those tables in reverse order. Historical outbox correction, reconciliation and bridge
migrations, migration-progress tracking and legacy recovery metadata were removed. The
package is unreleased, so disposable development installations that ran earlier Task 05
stubs must recreate their package outbox schema before applying this migration. The
package does not automatically reset a host database; `migrate:fresh` must not be run
against a host application database or production database copy.

Ambiguous updates now have two focused operator decisions: confirm the revision applied,
or explicitly authorize replay. Both require an active matching contact mapping and a
trusted attestation that the previous worker/request cannot mutate Mautic again. A remote
read alone is insufficient. State and audit commit atomically, competing/repeated choices
fail conditionally, and `retryFailed()` cannot bypass the ambiguity barrier.

The bounded poller selects only the lowest unresolved revision per aggregate before its
limit and connection-block filtering, avoiding starvation while retaining expired-worker
recovery. When create returns an ID and its first evidence write fails, the processor now
carries that exact ID into the owned ambiguity transition. If storage stays unavailable
or the process dies first, expiry remains the conservative fallback. Runtime coverage
retains operator recovery, fault injection, poller fairness and separate PHP processes
over one shared file-backed SQLite database; the concurrency evidence is not an
in-memory fake.

Local `composer check` passed Composer validation, Pint, Larastan level 8 and 151 tests
/ 633 assertions; the two opt-in MariaDB tests were skipped in that default run. The
dedicated disposable MariaDB outbox suite then passed the final schema and shared lease
contract with 1 test / 12 assertions. No host database was reset, no live Mautic endpoint
was called, and remote CI was not rerun because no authorized push occurred.

## Contact identity correction — 2026-10-19

Opaque connection, scope and key values now have byte-exact identity semantics across
database collations. Case, accents and significant leading/trailing whitespace remain
unchanged. Fresh mapping tables enforce both identity directions through 64-character
SHA-256 hashes of length-prefixed tuple components; repository lookups verify the
original tuple after every hash match. Claims, reverse lookup, generation transitions
and explicit relinking use the same keys.

The canonical create migration contains the complete final hashes and inventory index.
The earlier development upgrade migration was removed by Task 07A because the package is
unreleased; disposable development installations recreate the package schema explicitly.

Exact-email discovery and writer duplicate preflight now share trim plus ASCII
case-folding for both structured query values and local response comparison. Structured
parameters retain special characters and pagination; discovery deduplicates repeated
pages by remote contact ID before deciding unique versus ambiguous. Intra-batch
duplicates use the same policy. Mapping remains an explicit host decision.

Focused correction tests cover case, accent and whitespace distinctions, connection
and scope isolation, round-tripping, exact-repeat idempotency, both claim conflicts,
stale state and relink conflicts, canonical-schema publication, normalized queries,
special characters, pagination and distinct remote IDs. The real-process SQLite test
still races two PHP processes over one shared file database; it is not a memory fake.
The disposable case-insensitive MariaDB suite is opt-in through
`MAUTIC_TEST_MARIADB_*` and was not configured locally, so MariaDB runtime behavior is
not claimed from SQLite evidence. No live Mautic endpoint was called.

Local `composer check` passed with strict Composer validation, Pint, Larastan level 8
and 114 passing tests / 441 assertions; the unconfigured MariaDB test was skipped.
The remote PHP/Laravel compatibility matrix was not rerun because no authorized push
occurred.

## Contact identity and field ownership — 2026-10-19

Recorded the schema, uniqueness, tenancy, concurrency and supported-database decision
in `docs/decisions/001-contact-identity-storage.md` before implementing storage. The
core uses an opaque host-defined `(scope, key)` reference and does not depend on User,
a guard, Eloquent or a tenant package. The optional publishable mapping table enforces
unique local and remote identity per named connection. Atomic claims are idempotent
only for the exact pair; crossed claims conflict. State changes and explicit relinking
use generation compare-and-set. Missing, deleted and merged observations never trigger
automatic creation, selection or relinking.

`contactDiscovery()->exactEmail()` uses the real SDK structured equality request and
returns explicit none, unique or ambiguous results without claiming a mapping. Email
is discovery data rather than identity. `FieldMapper` combines explicit host and
Mautic aliases with host-owned, Mautic-owned or explicitly resolved shared ownership.
It retains unchanged, clear and set intent and rejects DNC, consent, tags, segments and
server-managed state from generic mapping. It does not deliver updates; that remains
Task 05.

Focused tests include a host-style Contact fixture with no User/guard dependency,
connection isolation, crossed claims, versioned recovery, merged-state review,
real-SDK exact-email ambiguity and allowlisted ownership. The concurrency integration
test starts two barrier-coordinated PHP processes that race different remote IDs for
one local reference over the same file-backed SQLite database; exactly one claim wins
and the other conflicts. This is not a memory fake. SQLite is runtime-proven; MariaDB
was not run locally.

Local `composer check` passed with strict Composer validation, Pint, Larastan level 8
and 107 tests / 378 assertions. It ran in the approved unrestricted environment because
PHPStan's loopback TCP server is unreliable in the local sandbox. No live Mautic or
production endpoint was called, and the remote PHP/Laravel matrix was not rerun for
these uncommitted changes.

## OAuth2 lifecycle — 2026-10-19

Implemented opt-in OAuth2 beside Basic Auth through an authentication factory. The
package exposes an explicit authorization URL/callback service but never emits an
interactive redirect, reads request globals or starts authorization from background
API work. Only authorization-code and refresh-token grants are implemented; no
client-credentials assumption was added.

The default database repositories store encrypted, versioned access/refresh tokens per
named connection. Callback state is hash-only at rest, expiring, bound to both the
connection and an opaque host-supplied authorization context, and conditionally
consumed once. Missing or mismatched trusted context does not consume state or change
tokens. The host must retain that context in trusted server-side state.

Callback exchange and refresh use the same owner-scoped database lock. Refresh re-reads
the token after acquisition, and both paths use generation compare-and-set persistence
before releasing the lock. An owner whose lease expired cannot overwrite a newer
rotation. Lock lease is validated as strictly longer than the request timeout. A remote
rotation followed by a failed or lost local write raises
`OAuthTokenPersistenceUncertain` and is never automatically retried.

Typed pre-request authorization, lock, endpoint and persistence failures survive the
upstream SDK boundary without sending a contact request. `invalid_grant`,
`invalid_client`, numeric `Retry-After` throttling, remote 5xx, network and malformed
token responses are classified without body/secret leakage. True post-send mutation
transport loss remains ambiguous. Instance-root normalization produces correct API and
OAuth endpoints for root and subdirectory URLs with or without `/api`.

The migration is explicitly publishable under `mautic-oauth-migrations` and the
aggregate `mautic-migrations` tag, and never runs automatically. Storage contracts,
table names, database connection and credential
provider are replaceable. Focused tests cover state context, replay/expiry/concurrency,
lease expiry, callback/refresh races, uncertain persistence, endpoint classification,
real-SDK contact reads/writes and unchanged Basic Auth behavior. The lock integration
test starts three barrier-coordinated real PHP processes over one shared file-backed
SQLite database; exactly one refresh occurs and all workers converge on the stored
rotation. A second barrier-coordinated two-process test proves one-time context-bound
state consumption. These are not memory fakes. SQLite is runtime-proven; MariaDB was
not run locally.

Local `composer check` passed with strict Composer validation, Pint, Larastan level 8
and 99 tests / 313 assertions. It ran in the approved unrestricted environment because
PHPStan's loopback TCP server intermittently cannot start in the local sandbox. No live
Mautic or production endpoint was called, and the remote PHP/Laravel matrix was not
rerun for these uncommitted changes.

## Contact write corrections — 2026-10-18

Corrected batch normalization against Mautic 7.2.1's actual response structure:
`contacts`, `statusCodes` and `errors` are separate arrays keyed by the original
request index. `ContactBatch` now exposes a complete ordered list of typed successful,
failed and unknown outcomes while retaining all successful remote contacts. Missing
targets are classified per item; malformed or incomplete items remain unknown. Batch
operations are explicitly non-atomic and no partial result is automatically retried.

Duplicate-email preflight now uses the SDK's structured `email eq` query, pages all
reported matches, and compares the returned email values after trimming and ASCII
case-folding. Counts and similar search results no longer block creation, while an
exact normalized match still stops the POST. The concurrent-create race remains.

Focused regression tests use the real SDK with fake HTTP and cover mixed batch
outcomes, indexes, missing targets, malformed/incomplete items, successful ID
retention, structured query parameters, pagination, similar emails and exact matches.
Local `composer check` passed with strict Composer validation, Pint, Larastan level 8
and 64 tests / 166 assertions. It ran in the approved unrestricted environment because
PHPStan's loopback TCP server is unavailable in the local sandbox. The latest remote
CI for base commit `d1ed594` passed all PHP 8.4/8.5 and Laravel 12/13 jobs plus Pint and
Larastan. These uncommitted corrections cannot have a remote CI result until they are
committed and pushed. No live Mautic instance or production endpoint was called.

## Contact writes — 2026-10-18

Implemented `Contracts\ContactWriter` through `MauticConnection::contactWrites()` for
allowlisted contact creation, PATCH-only partial updates, create/update batches and
manual segment add/remove commands. Omitted fields are absent from JSON while null,
false, zero and empty strings are transmitted explicitly. IDs, tags, DNC and selected
server-managed fields are outside generic writes.

Updates never enable the SDK's PUT/create-if-missing branch. Creates with a non-empty
email preflight existing matches and batch creates reject repeated emails before the
write. This prevents known merges but not a concurrent read-before-write race. Each
batch response item is normalized independently. Create transport loss is surfaced
as an ambiguous outcome and no mutation is retried automatically.

The segment commands use the installed SDK's manual membership endpoints and do not
change consent, DNC, fields or mail state. Documentation records dynamic-filter lag,
manual exclusions and the fact that REST creates do not execute form submit actions.
That contact-write task added no remote deletion, DNC removal, observer, queue or durable
delivery mechanism. Local `composer check` passed with strict Composer validation, Pint, Larastan
level 8 and 61 tests / 141 assertions. It ran in the approved unrestricted environment
because PHPStan's loopback TCP server is unavailable in the local sandbox. No live
Mautic instance or production endpoint was called, and the remote PHP/Laravel
compatibility matrix was not rerun for this change.

## Contact reads — 2026-10-18

Implemented `Contracts\ContactReader` through `MauticConnection::contactReads()` for
remote-ID lookup, explicit offset/limit search and one contact's segment memberships.
The immutable DTO boundary preserves missing, null and present-empty contact fields,
tags and DNC values. Search returns all page matches without implicit selection.

The shared SDK response boundary classifies authentication, validation, timeout,
throttling, malformed response, unexpected shape, remote server and other transport
failures. A missing contact is distinct from a successful empty membership. Public
exceptions omit upstream messages and payloads. All read operations are declared
side-effect-free and safe for an orchestrator to retry, but no automatic retry was
added. `FakeContactReader` implements the same host-facing contract.

Focused tests use the installed Mautic API library 4.0.0 with Guzzle MockHandler and
also cover the fake. Local `composer check` passed with strict Composer validation,
Pint, Larastan level 8 and 44 tests / 93 assertions. The local sandbox could not open
PHPStan's loopback TCP server, so the same command was rerun successfully in the
approved unrestricted environment. No live Mautic instance or production endpoint
was called. The remote PHP/Laravel compatibility matrix was not rerun for this change.

## Initialization verification — 2026-10-18

Verified runtime source revision: `e6cde979892a626836a606746643bb07e1060dcf`.
[GitHub CI run #2](https://github.com/trianity/laravel-mautic/actions/runs/39165107037)
completed successfully: all four PHP 8.4/8.5 × Laravel 12/13 matrix entries, strict
Composer validation, Pint (17 PHP files) and Larastan level 8 (10 source files).
Each matrix entry ran 30 passing tests with 45 assertions. No live Mautic calls ran.

Observed preferred resolution: PHP 8.5.11, Laravel 13.37.0, Testbench 11.3.0,
Pest 5.3.3, Mautic API library 4.0.0, Pint 1.32.1, Larastan 3.13.0 and PHPStan 2.3.1.
Observed Laravel 12 resolution: Laravel 12.70.0, Testbench 10.12.0 and Pest 4.7.8.
These are evidence from that run, not pinned dependency guarantees. Library consumers
resolve supported stable versions through Composer; recheck after dependency changes.

The first CI run's tests and Pint passed; its sole Larastan failure was a redundant
URL type check, fixed in the verified revision. JSON/XML parsing, repository Markdown
links and whitespace checks also passed in the initialization workspace.
