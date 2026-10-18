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

===

Review and correct Task 02 before starting OAuth2.

Read AGENTS.md, .ai/STATE.md and docs/ARCHITECTURE.md.
Inspect the installed Mautic SDK and Mautic 7.2.1 server source.
Use Pest TDD and preserve PHP 8.4 / Laravel 12 compatibility.

1. Correct batch response handling.
   Mautic returns contacts, statusCodes and indexed errors separately.
   Preserve original request indexes and classify every item.
   Expose successful, failed and unknown item outcomes through a typed
   result or a batch exception carrying the complete normalized result.
   Do not discard successful remote IDs when another item fails.
   Never imply atomicity or automatically retry a partially successful batch.
   Keep the fake consistent with the public contract.

2. Correct duplicate-email detection.
   Use verified exact-search semantics and compare returned email values
   using a documented normalization policy.
   Do not infer duplication solely from the search result count.
   Handle query escaping, wildcard characters and pagination correctly.
   Retain the documented concurrent-create race limitation.

Add regression tests using the real SDK with fake HTTP:
- mixed-success responses matching Mautic's actual batch structure;
- indexed errors, missing targets, malformed/incomplete results;
- retention of successful IDs and original indexes;
- a similar but different email must not block creation;
- an exact duplicate must block creation before POST.

Update README, CHANGELOG and .ai/STATE.md.
Run composer check and verify the remote compatibility CI.
Do not implement OAuth2 or modify upstream repositories in this task.