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
