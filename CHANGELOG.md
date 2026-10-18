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

No stable release, Packagist publication, OAuth2, durable synchronization, remote
deletion or general DNC-removal API is included.
