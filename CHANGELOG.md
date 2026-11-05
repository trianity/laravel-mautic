# Changelog

## 1.2.0 - 2026-11-05

### Added

- Additive typed membership plans for durable present/absent requirements while preserving
  the 1.1 single-segment API, stored intents and custom transport contract.
- Per-item durable progress, deterministic remove-then-add execution, full-plan verification,
  contact-revision ordering and audited observation/attestation recovery.
- A forward subscription migration for plan items and an opt-in mixed add/remove live
  acceptance path without changing DNC, consent, campaigns or mail.

### Compatibility

- Guzzle remains `^7.9`. Stable Guzzle 8 requires `guzzlehttp/psr7` 3.x, while the latest
  stable `mautic/api-library` 4.0.0 requires `guzzlehttp/psr7:^2.4`; no unsupported SDK
  branch or unrelated dependency change is used to advertise an unresolvable combination.
- Existing 1.1 rows are not rewritten. Run the new subscription migration before restarting
  workers; existing custom `SubscriptionTransport` implementations remain valid for legacy work.

### Fixed

- Treat an unchanged MariaDB subscription transition as successful only when the same
  generation-bound, unexpired worker reservation still exists. This preserves mixed-plan
  progress when consecutive items enter the same stage without weakening stale-worker checks.

### Upgrade

- Publish `mautic-subscription-migrations`, run migrations, then restart queue workers.
  Keep `recordPlan()` disabled until every instance runs 1.2 and the forward migration has
  completed. Hosts opt into plans through `SubscriptionDelivery::recordPlan()` and must supply a
  `MembershipPlanTransport` only when replacing the default manager transport.
- Stop or fully drain 1.1 subscription and contact-outbox workers before deploying 1.2. Do not
  let old workers overlap the package update or migration; follow `docs/UPGRADING.md` for the
  supported order and rollback boundary.

## 1.1.0 - 2026-10-24

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
