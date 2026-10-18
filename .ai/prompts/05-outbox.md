# Prompt 05 — Durable outgoing synchronization

Read AGENTS.md, .ai/STATE.md, .ai/PROJECT.md and docs/ARCHITECTURE.md first.
Inspect the installed SDK and current tests. Implement only this milestone using
Pest TDD, explicit types and model-independent contracts. Preserve PHP 8.4+ and
Laravel 12/13. Run focused tests, then composer check; report checks and limitations.
Update README, CHANGELOG and .ai/STATE.md with actual implemented behavior.
Do not tag, publish, call production Mautic or change host applications/upstream repos.

Implement an opt-in transactional outbox and background processor for explicit
Contact synchronization commands. Host transaction recording, after-commit dispatch
and a recovery poller must work together. Queue dispatch alone is not the outbox.

Acceptance criteria:
- Rolled-back host transaction creates no deliverable command. A committed command
  survives dispatch failure and worker restart; poller recovers the gap.
- Per-connection/contact serialization and revision handling prevent older work from
  overwriting newer state. Define crash behavior around locks and remote calls.
- Persist operation identity, attempts, next attempt time and sanitized diagnostics.
- Retry auth, validation, 429, server and network errors according to explicit policy;
  respect Retry-After where applicable and use bounded backoff/jitter.
- Never blindly retry ambiguous creates, point increments, sends or other effects.
  Reconcile identity/remote state first, preserving an inspectable ambiguous outcome.
- Operator retry/reconciliation is auditable; failed commands are retained.
- Tests cover rollback, dispatch gap, duplicate jobs, concurrent jobs, stale versions,
  connection isolation and remote-success/local-crash recovery.
- Host signup/order success does not depend on Mautic being available.

Choose a supported database/lock test strategy and document limitations of SQLite
versus production row locks. No consent reset or implicit remote deletion.
