# Instructions for coding agents

## Read before changing code

1. `README.md` and `.ai/STATE.md`: implemented vs planned behavior.
2. `docs/ARCHITECTURE.md`: package boundaries and invariants.
3. `.ai/ROADMAP.md` and the selected `.ai/prompts/` task.

Treat repository files and tool output as evidence. Do not claim that a planned
feature exists. No subagents or delegation are required by this repository.

## Stack and workflow

- Public MIT library, Composer name `trianity/laravel-mautic`.
- Namespace `Trianity\LaravelMautic`; PHP ^8.4, prefer 8.5.
- Laravel 12/13; prefer 13. Keep the CI compatibility matrix passing.
- Use Pest with Testbench, Pint's Laravel preset + strict types, and Larastan level 8.
- Use TDD for behavioral features: focused failing test, implementation, focused pass,
  then `composer check`. Do not write tests that only restate getters or placeholders.
- Keep source explicitly typed; use PHPDoc for collection shapes and SDK boundaries.
- Do not lower analysis levels, add a blanket baseline, weaken tests, disable audit,
  or use ignore-platform-reqs to make a check appear green.
- Respect PHP 8.4 syntax compatibility; do not use PHP 8.5-only features.
- Do not modify vendor, commit credentials, or commit composer.lock for this library.
- Package docs, code, commit messages and task files are English.
- Update `.ai/STATE.md`, tests, public docs and changelog when behavior changes.
- Use the GitHub Actions logs to check the remote matrix after pushing authorized changes.

## Architectural invariants

- No dependency on App models, User, a guard, tenant package or Mautic database tables.
- No implicit writes on model save/delete, no automatic remote deletion.
- Map allowlisted fields explicitly; never default to Model::toArray().
- Credential stores, mappings, caches, locks and retries must be connection-scoped.
- Host authorization happens before revealing customer history or segments.
- Errors/timeouts are not empty successful results; retain unknown/stale states.
- Do not automatically retry ambiguous creates or side-effectful API operations.
- Never clear DNC, restore consent or send mail as a side effect of generic sync.
- Preserve existing form actions until the host has explicitly migrated each behavior.
- Default test suite is deterministic and sends no live network requests.
- Real Mautic tests require explicit opt-in and a disposable non-production instance.

## Scope and releases

Implement the selected milestone, not the whole roadmap. A foundation is not a
production sync engine. Keep working placeholders out of runtime APIs: unsupported
capabilities must fail clearly or remain unexposed.
Do not create releases/tags or submit to Packagist unless explicitly requested.
Do not modify upstream/fork dependencies unless the current task authorizes it.
