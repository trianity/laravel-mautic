# Prompt 07 — Reconciliation, diagnostics and real-server proof

Read AGENTS.md, .ai/STATE.md, .ai/PROJECT.md and docs/ARCHITECTURE.md first.
Inspect the installed SDK and current tests. Implement only this milestone using
Pest TDD, explicit types and model-independent contracts. Preserve PHP 8.4+ and
Laravel 12/13. Run focused tests, then composer check; report checks and limitations.
Update README, CHANGELOG and .ai/STATE.md with actual implemented behavior.
Do not tag, publish, call production Mautic or change host applications/upstream repos.

Add bounded resumable reconciliation and useful diagnostics. Provide opt-in tests
against a disposable non-production Mautic installation. Verify and document the
actual supported server versions instead of claiming compatibility from SDK support.

Acceptance criteria:
- Per-connection checkpoints, stable pagination/watermarks, overlap/deduplication and
  rate limits; restarting a scan does not lose or duplicate business effects.
- Failed pages or incomplete scans never imply deletion or an empty segment list.
- Detect stale mappings, pending/ambiguous commands, failed inbox work and token
  health without exposing secrets or full contact data.
- Recovery commands support dry-run and bounded batches; dangerous repairs are explicit.
- Real-server tests are excluded from composer test, require explicit opt-in, validate
  the configured disposable target and clean up only their own identified fixtures.
- Verify Basic Auth, OAuth refresh, contact patch semantics, DNC, segment reads/manual
  commands, dynamic segment timing and webhook delivery where supported.
- Add host migration guide: inventory current form actions, add REST reads first,
  then migrate writes deliberately without duplicate campaign/mail effects.

Record tested PHP/Laravel/SDK/Mautic versions and the limits of each test. Do not
mark real-server acceptance complete when credentials/environment are unavailable.
