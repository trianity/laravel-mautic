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

### Fixed

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

- Enforce byte-exact contact identities with collation-independent tuple hashes,
  original-value verification and an explicit validating upgrade migration.
- Normalize structured email query values consistently for discovery and duplicate
  preflight, and count repeated paginated contacts by distinct remote ID.
- Normalize Mautic's separate indexed batch contacts, status codes and errors into
  complete per-request outcomes without discarding successful contacts on partial failure.
- Use paginated structured email equality queries and compare normalized returned
  values, avoiding false duplicate detection from counts or search syntax characters.
- Coordinate callback exchange with refresh, prevent stale lock owners from overwriting
  newer token rotations, classify token endpoint failures, and canonicalize instance
  root/API URLs for root and subdirectory installations.

No stable release, Packagist publication, webhook inbox, remote deletion or general
DNC-removal API is included. OAuth2 client-credentials is not implemented.
