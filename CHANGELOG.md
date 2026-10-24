# Changelog

## 1.1.0 - Unreleased

### Added

- Optional, disabled-by-default browser OAuth start and callback routes with host middleware,
  deny-by-default host authorization, connection allowlisting, session-held random contexts
  and sanitized result handling.
- One canonical callback URL resolver shared by authorization, token exchange and the new
  read-only `mautic:oauth:callback-url` diagnostic command.
- Laravel runtime environment repository support for OAuth client secrets while retaining
  process-environment compatibility and custom credential-provider bindings.
- A host adoption guide for migrating duplicated OAuth infrastructure without resetting
  tokens or moving host business authorization into the package.
- A complete first-connection procedure with concrete authorizer binding, a CSRF-protected
  start action and safe published-configuration upgrade notes.

### Fixed

- Result-route redirects now retain a relative `Location` header instead of rebuilding an
  absolute URL from the request host.
- A callback for a replaced authorization start no longer removes the newer browser
  context, so the current authorization can still complete.

### Compatibility

- Existing explicit `redirect_uri` values and host-owned OAuth routes remain authoritative.
- Upgrades register no browser routes unless explicitly enabled; migrations and persisted
  OAuth/token formats are unchanged.

## 1.0.0 - 2026-10-24

### Added

- PHP 8.4+ and Laravel 12/13 package foundation with lazy named Basic Auth and OAuth2
  connections over the official Mautic API library.
- Explicit, allowlisted contact reads and writes, manual static-segment operations and
  typed email-restriction operations without implicit model hooks or remote deletion.
- Optional connection-scoped persistence for OAuth tokens, contact identity, the
  transactional outbox, verified webhook inbox, reconciliation and durable subscription
  delivery, with one canonical migration and one publication tag per subsystem.
- Conservative concurrency, retry and operator-recovery contracts for ambiguous remote
  mutations, token rotation, event processing, reconciliation and segment provisioning.
- Source-only opt-in acceptance harnesses with scoped Mautic 7.2.1 evidence for the
  documented contact, OAuth, restriction and subscription capabilities.
- Pest/Testbench coverage, Pint, Larastan level 8 and a PHP 8.4/8.5 with Laravel 12/13 CI
  matrix.

### Release preparation

- Added the dedicated `mautic-oauth-migrations` publication tag while preserving the
  six-migration `mautic-migrations` aggregate and every subsystem-specific tag.
- Declared the directly used JSON extension and documented migration dependencies.
- Excluded tests, AI/development tooling and the optional raw OpenAPI snapshot from the
  distribution while retaining consumer documentation, runtime configuration and all
  migration stubs.

### Known boundaries

- Compatibility evidence is version- and capability-specific; host business integration
  has not been verified as a generic workflow.
- Campaign configuration and execution remain Mautic responsibilities and are separate
  from contact or segment operations supplied by this package.
- The package does not offer independently owned restriction state, automatic consent
  restoration, remote exactly-once delivery or automatic retry of ambiguous creates.
