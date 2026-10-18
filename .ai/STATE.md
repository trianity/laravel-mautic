# Current state

## Implemented foundation

- Composer metadata and MIT license, PHP 8.4+, Laravel 12/13 constraints.
- Lazy named connections with Basic Auth via the official API library.
- Validated configuration, finite request/connect timeouts, TLS verification,
  no redirects, no automatic retry, replaceable transport/connection factories.
- Provider auto-discovery, publishable config, optional facade and raw SDK contexts.
- Pest unit/feature tests, Testbench, Pint, Larastan level 8, CI matrix.
- Ordered implementation prompts and architecture/release/contribution guidance.

## Not implemented

Typed DTO services, normalized response errors, contact writes with identity policy,
OAuth2/token store, local mappings, outbox/inbox, webhook endpoint, reconciliation,
consent workflows and live Mautic integration tests. No migrations or automatic
model events exist. No release tag or Packagist publication exists.

## Verification

See the initialization verification entry appended after the first CI run.
The initialization workspace has no PHP/Composer; runtime checks use GitHub Actions.
Tests exercise the real SDK with fake HTTP, not a real Mautic installation.

## Next task

Run `prompts/00-resume.md` locally. Then implement `prompts/01-contact-reads.md`.
The roadmap contains design gates; inspect before implementing later stages.
