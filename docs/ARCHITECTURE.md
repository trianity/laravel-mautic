# Architecture and contracts

## Boundaries

| Layer | Responsibility | Owner |
| --- | --- | --- |
| Official SDK | Mautic REST contexts and authentication primitives | mautic/api-library |
| Laravel package | Named connections, transport, normalized services and durable integration infrastructure | trianity/laravel-mautic |
| Host domain | Contact model, identity verification, field ownership, CRM workflows and consent evidence | Host application |

The connection foundation, normalized contact reads, explicit partial writes and
manual segment commands are implemented today. The remaining sections state design
constraints for later milestones; they are not a feature list.

## Current runtime

`MauticServiceProvider` merges config and lazily registers `MauticManager`.
The manager validates a named `ConnectionConfig`, calls `ConnectionFactory` and
caches the resulting `MauticConnection`. `ApiLibraryConnectionFactory` constructs
upstream BasicAuth with the PSR-18 transport from `HttpClientFactory`.
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
ASCII case-folding. They do not trust a search count or interpolate user values into
Mautic's search-command syntax.

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

Use a connection-scoped mapping between an opaque local reference and Mautic ID.
Do not require Eloquent, a User model or a guard in the core contract. If optional
Eloquent mapping storage is added, use a morph-map-aware reference or an adapter,
with uniqueness constraints and a documented tenancy boundary.
Email lookup helps initial matching. Zero, one and multiple matches are separate
outcomes; do not silently merge or pick the first result. Email changes must retain
mapping continuity. A deleted/merged remote contact requires explicit recovery.

Field ownership belongs to the host's policy: CRM-owned, Mautic-owned, or explicitly
resolved shared fields. Billing details, operator notes and auth data must not
be exported by default. Mapping is an allowlist, not serialization of an entire model.

Segment membership is a marketing classification, not an authentication permission.
Dynamic membership can lag the related field update. Distinguish static/manual
membership commands from filter-based membership and test exclusions/re-addition.
A DNC record, marketing consent and segment membership are different concepts.
Generic updates must never imply renewed consent or remove DNC.

The implemented segment commands use only manual add/remove endpoints. They do not
change fields, consent, DNC or mail state. Dynamic filter evaluation is asynchronous;
manual removal may be represented as an exclusion until explicitly re-added. Remote
deletion and general DNC removal are not exposed.

## Reliable delivery (planned)

- Outbox: host transaction records a durable command; dispatch after commit; a poller
  recovers dispatch gaps. Queue retries alone do not guarantee durable handoff.
- Serialize concurrent commands for the same connection/contact when ordering matters.
  Record source, operation ID, revision/hash and processing outcome.
- A timeout after a create may mean remote success. Mark ambiguous state, reconcile
  before retrying; do not assume the endpoint provides an idempotency key.
- Inbox: verify raw-body HMAC first, persist events durably, acknowledge, then process.
  Handle multiple event types and multiple items in one delivery. Separate delivery
  deduplication from event deduplication and do not suppress legitimate repeated events.
- No global exactly-once claim. Prevent sync loops using field ownership and change
  detection, not just a short-lived process flag. Handle duplicates and stale events.
- Reconciliation: bounded, paginated, resumable and observable. Do not infer deletion
  from an incomplete scan or a failed page. Missing events must be recoverable.

Use the SDK's error payloads and transport exceptions to distinguish auth failures,
validation failures, throttling, transient server errors and ambiguous mutation
outcomes. Future retry metadata must describe the operation's safety. Never add a
blanket retry middleware to this transport.

## Authentication roadmap

Basic Auth uses a dedicated least-privilege integration user over HTTPS. OAuth2
must persist encrypted access/refresh tokens per connection, validate callback state,
serialize refresh across workers and atomically save rotated tokens before release
of the lock. A process-local token object or environment-variable refresh token alone
is insufficient. Verify grants supported by the target Mautic version; do not assume
client credentials exists. No interactive redirects from a queue job or API request.

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
