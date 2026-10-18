# Prompt 02 — Explicit partial writes and segment commands

Read AGENTS.md, .ai/STATE.md, .ai/PROJECT.md and docs/ARCHITECTURE.md first.
Inspect the installed SDK and current tests. Implement only this milestone using
Pest TDD, explicit types and model-independent contracts. Preserve PHP 8.4+ and
Laravel 12/13. Run focused tests, then composer check; report checks and limitations.
Update README, CHANGELOG and .ai/STATE.md with actual implemented behavior.
Do not tag, publish, call production Mautic or change host applications/upstream repos.

Add normalized explicit contact creation, partial update and static/manual segment
membership commands. Reuse the read and error contracts from Task 01. Verify exact
SDK endpoint semantics; do not infer PUT/PATCH behavior from method names.

Acceptance criteria:
- Allowlisted field payloads; omitted field vs explicit null vs false/zero/empty
  string have documented and tested behavior. Never serialize a whole model.
- Never silently create on a missing-ID update or silently merge duplicate contacts.
- Check error payloads on edits and every batch item as well as creation.
- Create timeout/connection loss after send is surfaced as ambiguous; no blind retry.
- Safe reads and side-effectful mutations have explicit retry classifications.
- Segment membership commands are not consent changes, DNC removal or mail sends.
- Filter-based membership delays/manual exclusions are explained and tested where
  feasible; prove endpoint requests with recorded fake HTTP.
- Remote deletion and general DNC-removal APIs are outside this task.

Document that API creation does not run form submit actions. Keep host migration
out of scope. No model observers, durable jobs or token lifecycle yet.
