# Architecture and contracts

## Boundaries

| Layer | Responsibility | Owner |
| --- | --- | --- |
| Official SDK | Mautic REST contexts and authentication primitives | mautic/api-library |
| Laravel package | Named connections, transport, normalized services and durable integration infrastructure | trianity/laravel-mautic |
| Host domain | Contact model, identity verification, field ownership, CRM workflows and consent evidence | Host application |

The connection foundation, normalized contact reads, explicit partial writes, manual
segment commands, identity mappings, field ownership, transactional outgoing contact
outbox, verified webhook inbox, bounded reconciliation and operational diagnostics are
implemented today. The remaining sections state design constraints for later milestones;
they are not a feature list.

## Current runtime

`MauticServiceProvider` merges config and lazily registers `MauticManager`.
The manager validates a named `ConnectionConfig`, calls `ConnectionFactory` and
caches the resulting `MauticConnection`. `ApiLibraryConnectionFactory` delegates to
an authentication factory, which constructs upstream BasicAuth or a managed OAuth2
bearer adapter with the PSR-18 transport from `HttpClientFactory`.
`contacts()` returns upstream Contacts; `api(context)` is a low-level escape hatch.
No outbound request occurs during registration, boot or connection construction.

`contactReads()` returns the package-owned `ContactReader`. Its SDK adapter sends
all responses through one normalization boundary and maps successful payloads to
immutable DTOs. Contact lookup, bounded search and contact-specific segment
membership reads are available. `OptionalValue` retains missing, explicit null and
present-empty distinctions. Normalized exceptions contain classification and bounded
metadata, never upstream messages or unrestricted response payloads. Read operations
publish safety metadata but are not retried automatically. `FakeContactReader`
provides the same contract for deterministic host tests.

`contactWrites()` returns the package-owned `ContactWriter`. `ContactFields` rejects
non-allowlisted and protected side-effect fields, while preserving explicit scalar,
null and empty values. Updates use PATCH with create-if-missing disabled. Creation
preflights non-empty email values because Mautic can merge duplicate-email creates;
known matches and intra-batch duplicates are rejected. This read-before-write check
does not eliminate concurrent races, so durable identity and reconciliation remain
future work.

The same response boundary classifies mutation failures. Batch results preserve the
server's request indexes and separately classify every item as successful, failed or
unknown; successful remote IDs survive sibling failures. A partial batch is never
treated as atomic or automatically retried.

Duplicate preflights use the SDK's structured exact-equality filter, paginate all
reported matches and compare returned email values after whitespace trimming and
ASCII case-folding. The query value uses that same normalization policy. They do not
trust a search count, server collation or interpolate user values into Mautic's
search-command syntax.

All mutations publish non-retryable, side-effectful operation metadata. Transport
loss during a create is an ambiguous outcome, not a safe retry. The deterministic
`FakeContactWriter` implements the same contract.

The factory contracts are the extension seam. Use composition around the SDK;
do not subclass its endpoint classes or leak their arrays through future typed APIs.
Raw access remains explicitly named/documented as an expert boundary.

## Normalized API rules

Contact reads and segment memberships return immutable DTOs and preserve
unknown/missing vs null vs empty values and pagination metadata. Partial writes send
only explicit allowlisted keys and retain null/false/zero/empty-string payload values.
Every response, including edits and batch items, is checked for errors; HTTP success
alone is insufficient.
Mautic documentation may lag behind code. Verify supported endpoints and payloads
against the installed SDK and a target disposable Mautic instance.

## Identity and field ownership

`LocalContactReference` contains an opaque host-defined `scope` and `key`. The scope
is the explicit tenancy/domain namespace; no Eloquent model, User, guard, current
tenant or request state is consulted. `ContactMappingRepository` is the core seam and
the optional database implementation is bound by default. Its two unique constraints
are SHA-256 keys for the length-prefixed `(connection, local_scope, local_key)` and
`(connection, remote_id)` tuples. The original byte-exact values remain authoritative,
round trip unchanged and are checked after every hash lookup. Case, accents and
leading/trailing whitespace are significant across SQLite and MariaDB/MySQL regardless
of database collation. Exact claims are idempotent; crossed claims conflict. Generation
compare-and-set protects remote state transitions and explicit relinking from stale
writers. A separate published migration validates and upgrades the earlier
collation-dependent development schema without silently merging incompatible rows.

Remote state is `active`, `missing`, `deleted` or `merged`; a merge may retain a
review target without claiming it. Observation never recreates or relinks a contact.
Exact-email discovery uses the SDK's structured equality query with the same trimmed,
ASCII-case-folded value used for local verification. It returns none, unique or
ambiguous contacts by distinct remote ID without writing a mapping. Email is not
stored as identity, so later email changes preserve mapping continuity.

`FieldMapper` consumes only `HostContactFields` with an explicit host allowlist and
explicit `FieldRule` aliases. Host-owned, Mautic-owned and shared host-wins/Mautic-wins
rules resolve to inspectable unchanged, clear or set decisions. Equal authoritative
and target values remain unchanged. The mapper does not perform delivery or serialize
a model. DNC, consent evidence, tags, segment membership and server-managed fields are
outside this contract. The full schema, concurrency and supported-database assumptions
are recorded in `docs/decisions/001-contact-identity-storage.md`.

Segment membership is a marketing classification, not an authentication permission.
Dynamic membership can lag the related field update. Distinguish static/manual
membership commands from filter-based membership and test exclusions/re-addition.
A DNC record, marketing consent and segment membership are different concepts.
Generic updates must never imply renewed consent or remove DNC.

The implemented segment commands use only manual add/remove endpoints. They do not
change fields, consent, DNC or mail state. Dynamic filter evaluation is asynchronous;
manual removal may be represented as an exclusion until explicitly re-added. Remote
deletion and general DNC removal are not exposed.

## Reliable outgoing delivery

The optional database outbox records an explicit allowlisted create or update command
inside the host transaction on the same database connection. Rollback removes it;
after-commit queue dispatch is only a wake-up mechanism. Dispatch failure leaves the
row eligible for the bounded recovery poller. Stable operation identity and aggregate
revision constraints make exact recording idempotent and reject conflicting reuse.

A database lease over the byte-exact connection/local-reference hash serializes workers
without holding a transaction across HTTP. Workers reload after lease acquisition and
deliver all stored revisions sequentially; incomplete lower revisions block newer work.
The lease must exceed a declared complete-operation budget, but it is not remote fencing.
An expired in-flight operation becomes ambiguous and blocks later delivery until
reconciliation. SQLite proves shared multi-process coordination and this expiry protocol
but does not model InnoDB row-lock scheduling; a disposable MariaDB suite checks the
canonical final schema, collation-independent aggregates and the shared lease contract.

Idempotent field updates can retry bounded transient authentication, throttling, server,
timeout and transport failures. Numeric `Retry-After` is propagated from the current SDK
response and is a minimum delay even above the local backoff cap. OAuth token uncertainty
or required reauthorization blocks the named connection until audited operator resume.
Validation, missing targets and exhausted attempts are retained. Stored diagnostics are
enumerated and do not contain upstream messages.

A create with a 5xx response, transport uncertainty, malformed success or local
persistence uncertainty becomes `ambiguous`; it is not automatically sent again. Its
returned remote ID is retained when available. An operator must explicitly claim a
verified remote ID or confirm remote absence before retry, and every operator decision
is audited. Full details are in
`docs/decisions/002-outbox-delivery.md`.

## Incoming delivery

The host passes an exact raw body and `Webhook-Signature` header to the opt-in inbox;
the package registers no route. Connection-specific secrets stay behind a replaceable
runtime provider. Body bounds apply before HMAC work, and base64 HMAC-SHA256 verification
precedes JSON parsing or persistence. A delivery and all of its encrypted event payloads
commit atomically before queue dispatch, while a bounded poller recovers dispatch gaps
and expired processing leases.

Delivery receipt identity and optional upstream semantic event identity are different.
Mautic does not document a delivery UUID and may omit event UUIDs and timestamps, so
identical authenticated deliveries are retained rather than collapsed by body hash.
Explicit event IDs deduplicate only after an earlier copy completed. Per-connection,
per-remote-contact database leases serialize processing; strict older-than ordering and
field change detection handle stale and repeated events. This is not an exactly-once or
freshness claim because the signed content contains no timestamp.

Allowed contact updates resolve an existing connection-scoped mapping, then use a host
bound `InboundContactProjector` and the existing `FieldMapper`. Only changed inbound
Mautic-owned/shared-winner values are applied. Host-owned fields cannot echo outward from
the package, and channel subscription, consent and DNC events are excluded from generic
field handling. Allowed deletes mark mapping state only. Unknown, disallowed and unmapped
events remain inspectable without exposing customer payloads in logs. Full details are
in `docs/decisions/003-webhook-inbox.md`.

## Reconciliation and diagnostics

The explicit reconciliation service captures a per-connection maximum remote ID and
advances an ascending ID keyset cursor in bounded pages. Its checkpoint, ID-only
observations and finding codes are durable. A page and its cursor commit atomically, so
a failed request or process crash replays the uncommitted page; unique scan/remote-ID
keys deduplicate that overlap. Configured page size, pages per run and minimum interval
bound load. A scan performs no host business write and never infers deletion or empty
segment membership from absence, an incomplete page or a failure.

Missing mapping recovery is separate. It keyset-pages active local mappings and checks
each remote ID. Dry-run is the default; only a normalized not-found result is actionable.
Applying requires explicit actor/reason input and same-database identity/reconciliation
storage so the generation-checked `missing` transition and audit commit atomically. No
automatic relink, recreation or deletion exists.

Connection diagnostics expose only status counts, current checkpoint metadata, finding
codes and non-secret OAuth token health. They do not decrypt credentials or return local
identity, contact, outbox or webhook payload data. Details are in
`docs/decisions/004-reconciliation.md`.

Use the SDK's error payloads and transport exceptions to distinguish auth failures,
validation failures, throttling, transient server errors and ambiguous mutation
outcomes. Future retry metadata must describe the operation's safety. Never add a
blanket retry middleware to this transport.

## Authentication

Basic Auth uses a dedicated least-privilege integration user over HTTPS. OAuth2
uses authorization-code and refresh-token grants. `OAuth2Service` creates an
authorization URL and completes a callback without performing redirects itself.
Callback state is random, hash-only at rest, connection-bound, expiring and atomically
single-use. It is also bound to an opaque authorization context supplied and retained
by the host. Callback context must come from trusted server-side state; missing or
mismatched context neither consumes state nor exchanges a code. The host must authorize
its own routes and choose when a browser redirect is appropriate.

The default token repository encrypts access and refresh tokens with the host
encrypter and stores one versioned record per connection. Callback exchange and refresh
share one connection-scoped database lock. Every refresh holder re-reads storage, and
both paths commit with a generation compare-and-set before the owner-scoped lock is
released. This prevents an old owner whose lease expired from overwriting a newer
rotation. The lock lease must exceed the connection request timeout. A successful
remote rotation followed by an uncertain or lost local write is not retried and raises
`OAuthTokenPersistenceUncertain`.

Missing or revoked refresh credentials raise `OAuthReauthorizationRequired`;
background work never redirects. Pre-request authorization, lock-timeout, token
endpoint and uncertain-persistence failures retain typed package exceptions through
the SDK boundary. Any other authentication failure before sending an API request is
wrapped as the secret-free `OAuthPreRequestFailed`. `invalid_grant`, `invalid_client`,
throttling, remote server, network and malformed token responses are classified
without exposing response bodies or secrets. Actual post-send mutation transport loss
remains an ambiguous mutation.
Token exchange and refresh are bounded single attempts.

The storage contracts and database/table settings are replaceable. The default
migration is publishable and never runs automatically. The client secret is resolved
at runtime from a named environment variable or a host credential-provider binding,
not stored in config cache. A process-local token object or memory-only lock is not
sufficient. The package does not implement or assume client-credentials support;
verify target Mautic behavior before expanding grants.

The concurrency suite uses separate PHP processes sharing a file-backed SQLite
database, including an explicit start barrier for concurrent state consumption. It
therefore proves the default database coordination on SQLite, not merely cooperation
between objects in one process. MariaDB uses the same conditional SQL contracts but
has not been runtime-verified by this local suite.

## SDK fork policy

Normally depend on a tested upstream `mautic/api-library` tag. The owner's existing
`trianity/api-library` fork preserves the upstream Composer package name. A temporary
fork source override belongs to the consuming root project's `repositories` section;
Composer does not load repository definitions transitively from this library.
Pin a reviewed fork tag/commit and document divergence and the return-to-upstream path.
Do not force every consumer to use the fork or publish an unbounded dev dependency.

## Existing form integration

API contact creation does not run or reproduce form submit actions. Inventory
form submit actions, campaign entry, notifications, attribution, segmentation and
consent handling before migrating a host. Initially add REST reads while retaining
the host's existing write path. Avoid parallel writes that duplicate business effects.
