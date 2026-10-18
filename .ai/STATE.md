# Current state

## Implemented foundation

- Composer metadata and MIT license, PHP 8.4+, Laravel 12/13 constraints.
- Lazy named connections with Basic Auth via the official API library.
- Validated configuration, finite request/connect timeouts, TLS verification,
  no redirects, no automatic retry, replaceable transport/connection factories.
- Provider auto-discovery, publishable config, optional facade and raw SDK contexts.
- Immutable normalized contact DTOs, bounded search and contact segment memberships.
- Normalized read failures, read-operation safety metadata and deterministic read fake.
- Allowlisted contact creates, PATCH-only partial updates and checked batch writes.
- Manual segment membership commands, mutation safety metadata and deterministic fake.
- Pest unit/feature tests, Testbench, Pint, Larastan level 8, CI matrix.
- Ordered implementation prompts and architecture/release/contribution guidance.

## Not implemented

OAuth2/token store, local identity mappings, durable outbox/inbox, webhook endpoint,
reconciliation, consent workflows and live Mautic integration tests. Remote deletion
and general DNC removal are not exposed. No migrations or automatic model events
exist. No release tag or Packagist publication exists.

## Verification

See the initialization verification entry appended after the first CI run.
The initialization workspace has no PHP/Composer; runtime checks use GitHub Actions.
Tests exercise the real SDK with fake HTTP, not a real Mautic installation.

## Next task

Review and select the next milestone. `prompts/03-oauth2.md` is next in roadmap order;
model-independent identity mapping follows in `prompts/04-contact-identity.md`.
Inspect the selected task's design gates before implementation.

## Contact writes — 2026-10-18

Implemented `Contracts\ContactWriter` through `MauticConnection::contactWrites()` for
allowlisted contact creation, PATCH-only partial updates, create/update batches and
manual segment add/remove commands. Omitted fields are absent from JSON while null,
false, zero and empty strings are transmitted explicitly. IDs, tags, DNC and selected
server-managed fields are outside generic writes.

Updates never enable the SDK's PUT/create-if-missing branch. Creates with a non-empty
email preflight existing matches and batch creates reject repeated emails before the
write. This prevents known merges but not a concurrent read-before-write race. Each
batch response item is normalized independently. Create transport loss is surfaced
as an ambiguous outcome and no mutation is retried automatically.

The segment commands use the installed SDK's manual membership endpoints and do not
change consent, DNC, fields or mail state. Documentation records dynamic-filter lag,
manual exclusions and the fact that REST creates do not execute form submit actions.
No remote deletion, DNC removal, observer, queue or durable delivery mechanism was
added. Local `composer check` passed with strict Composer validation, Pint, Larastan
level 8 and 61 tests / 141 assertions. It ran in the approved unrestricted environment
because PHPStan's loopback TCP server is unavailable in the local sandbox. No live
Mautic instance or production endpoint was called, and the remote PHP/Laravel
compatibility matrix was not rerun for this change.

## Contact reads — 2026-10-18

Implemented `Contracts\ContactReader` through `MauticConnection::contactReads()` for
remote-ID lookup, explicit offset/limit search and one contact's segment memberships.
The immutable DTO boundary preserves missing, null and present-empty contact fields,
tags and DNC values. Search returns all page matches without implicit selection.

The shared SDK response boundary classifies authentication, validation, timeout,
throttling, malformed response, unexpected shape, remote server and other transport
failures. A missing contact is distinct from a successful empty membership. Public
exceptions omit upstream messages and payloads. All read operations are declared
side-effect-free and safe for an orchestrator to retry, but no automatic retry was
added. `FakeContactReader` implements the same host-facing contract.

Focused tests use the installed Mautic API library 4.0.0 with Guzzle MockHandler and
also cover the fake. Local `composer check` passed with strict Composer validation,
Pint, Larastan level 8 and 44 tests / 93 assertions. The local sandbox could not open
PHPStan's loopback TCP server, so the same command was rerun successfully in the
approved unrestricted environment. No live Mautic instance or production endpoint
was called. The remote PHP/Laravel compatibility matrix was not rerun for this change.

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
