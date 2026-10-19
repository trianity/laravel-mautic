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

### Fixed

- Normalize Mautic's separate indexed batch contacts, status codes and errors into
  complete per-request outcomes without discarding successful contacts on partial failure.
- Use paginated structured email equality queries and compare normalized returned
  values, avoiding false duplicate detection from counts or search syntax characters.
- Coordinate callback exchange with refresh, prevent stale lock owners from overwriting
  newer token rotations, classify token endpoint failures, and canonicalize instance
  root/API URLs for root and subdirectory installations.

No stable release, Packagist publication, durable synchronization, remote deletion or
general DNC-removal API is included. OAuth2 client-credentials is not implemented.
