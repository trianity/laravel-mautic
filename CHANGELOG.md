# Changelog

## Unreleased

### Added

- PHP 8.4+ / Laravel 12 and 13 package foundation under the MIT license.
- Lazy named Basic Auth connections over the official Mautic API library.
- Validated configuration, bounded PSR-18/Guzzle transport and container extension points.
- Provider auto-discovery, publishable configuration and optional facade.
- Pest/Testbench unit and feature tests with mocked HTTP, Pint and Larastan setup.
- PHP 8.4/8.5 and Laravel 12/13 CI matrix and Codex implementation roadmap.
- Immutable DTOs and a model-independent contract for contact lookup, bounded contact
  search and contact-specific segment membership reads.
- Normalized, payload-safe read failures with explicit operation safety metadata and
  a deterministic `FakeContactReader` for host application tests.
- Allowlisted contact creation, PATCH-only partial updates, checked batch mutations
  and deterministic `FakeContactWriter` support.
- Explicit manual segment add/remove commands, mutation safety metadata and ambiguous
  create outcomes without automatic retries.
- Opt-in OAuth2 authorization-code and refresh-token lifecycle with encrypted,
  connection-scoped database token storage, one-time expiring state and publishable
  namespaced migrations.
- Database-shared refresh locking with lock-time token re-reads and atomic persistence
  of rotated access and refresh tokens before release.
- Host-context-bound OAuth state, versioned token compare-and-set persistence and typed
  pre-request authentication failures across the upstream SDK boundary.
- Model-independent, connection-scoped contact identity mappings with optional
  publishable Laravel persistence and versioned explicit recovery.
- Exact-email discovery with none/unique/ambiguous outcomes and allowlisted field
  ownership resolution that preserves unchanged, clear and set intent.
- Optional transactional contact outbox with after-commit dispatch, recovery polling,
  shared aggregate leases, revision ordering and retained sanitized outcomes.
- Audited operator recovery for ambiguous creates and failed commands, with bounded
  retry policy and numeric `Retry-After` propagation.
- Attested, audited ambiguous-update confirmation and replay authorization.
- Opt-in verified Mautic webhook ingress with raw-body HMAC authentication, encrypted
  durable batch storage, asynchronous recovery and shared per-contact processing leases.
- Model-independent inbound contact projection through existing mappings and field
  ownership, including stale-event handling and explicit consent/DNC separation.
- Bounded resumable contact reconciliation with per-connection high-water checkpoints,
  keyset pagination, replay deduplication, request spacing and data-minimized findings.
- Connection diagnostics and dry-run-first, bounded, audited missing-mapping recovery.
- A separately invoked loopback-only live Mautic suite and staged host migration guide.
- A provenance-tracked Mautic 7.2.1 API v2 OpenAPI snapshot and evidence-based
  capability inventory for future adapter evaluation.
- An isolated, exact-origin OAuth Authorization Code acceptance harness with encrypted
  persistent test tokens, verified local TLS and bounded read-only API probes.
- A separately gated OAuth contact-lifecycle acceptance suite with durable ignored
  ownership evidence, exact cleanup checks and optional dedicated static-segment proof.
- Typed delivery-restriction reads with complete available DNC metadata, known-reason
  mapping, preserved unknown values and explicit unavailable bounce-subtype evidence.
- A separately gated, metadata-preflighted OAuth custom-field lifecycle probe and a
  design for atomic, independently owned administrative restrictions.

### Fixed

- Send Mautic's adapter-owned blank-overwrite control for explicit null or empty-string
  contact PATCH values, preventing successful no-op clears on Mautic 7.2.1.
- Measure the controlled OAuth refresh generation baseline immediately before expiry
  simulation, including when an earlier API read refreshed an initially expired token.
- Normalize non-negative numeric-string collection totals returned by the Mautic 7.2.1
  API through the upstream SDK while retaining strict integer DTO contracts.

- Bind reconciliation reservation readback to the exact owner, generation, scan and
  expiry created by that invocation, preventing ownership substitution during handoff.
- Reserve reconciliation HTTP work atomically in shared storage, reject stale results,
  recover abandoned reservations and retain accurate durable request spacing/counts.
- Preserve reconciliation `Retry-After` metadata, validate SDK pagination evidence and
  block unsafe OAuth persistence/reauthorization outcomes.
- Select mapping inventory through byte-exact connection hashes before pagination limits.
- Prepare and verify the live OAuth output before rotation, persist complete tokens, and
  structurally match signed live webhook update evidence.
- Consolidate the unreleased identity schema into one canonical create migration without
  a development compatibility bridge.

- Preserve every partial outbox revision in sequential aggregate order, including
  create-before-update prerequisites and explicit blocking behind unresolved commands.
- Treat expired in-flight operations and uncertain create persistence as reconciliation
  requirements, retaining remote IDs when available and preventing unsafe later delivery.
- Preserve typed OAuth blockers and transient token-endpoint metadata, and honor valid
  `Retry-After` values as minimum delays rather than capping them to local backoff.
- Avoid uninitialized or stale upstream SDK response headers after transport failures.
- Avoid bounded-poller starvation and retain locally returned create IDs when their
  first evidence write fails.
- Replace unreleased incremental outbox migrations with one canonical final-schema
  migration and no development-version compatibility machinery.

- Enforce byte-exact contact identities with collation-independent tuple hashes and
  original-value verification.
- Normalize structured email query values consistently for discovery and duplicate
  preflight, and count repeated paginated contacts by distinct remote ID.
- Normalize Mautic's separate indexed batch contacts, status codes and errors into
  complete per-request outcomes without discarding successful contacts on partial failure.
- Use paginated structured email equality queries and compare normalized returned
  values, avoiding false duplicate detection from counts or search syntax characters.
- Coordinate callback exchange with refresh, prevent stale lock owners from overwriting
  newer token rotations, classify token endpoint failures, and canonicalize instance
  root/API URLs for root and subdirectory installations.

No stable release, Packagist publication, automatic webhook route, remote deletion or
general DNC-removal API is included. OAuth2 client-credentials is not implemented.
