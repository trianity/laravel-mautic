# Prompt 01 — Typed contact and segment reads

Read AGENTS.md, .ai/STATE.md, .ai/PROJECT.md and docs/ARCHITECTURE.md first.
Inspect the installed SDK and current tests. Implement only this milestone using
Pest TDD, explicit types and model-independent contracts. Preserve PHP 8.4+ and
Laravel 12/13. Run focused tests, then composer check; report checks and limitations.
Update README, CHANGELOG and .ai/STATE.md with actual implemented behavior.
Do not tag, publish, call production Mautic or change host applications/upstream repos.

Create package-owned read contracts and immutable DTOs for finding a contact by
remote ID, searching contacts and listing one contact's segment memberships.
Keep existing raw access explicitly separate and document the normalized API.
Introduce a single response/error boundary for SDK arrays and transport exceptions.

Acceptance criteria:
- Cover contact fields, tags and DNC without equating missing/null/empty values.
- Pagination is bounded and explicit; searches do not silently select the first match.
- Segment membership differs from the global segment catalogue.
- HTTP and payload errors are normalized, including errors in HTTP 200 responses.
- Missing contact, empty membership, auth failure, validation failure, timeout,
  throttling, malformed JSON and unexpected response shape are distinct outcomes.
- Exceptions/log context never expose credentials or unrestricted personal payloads.
- Provide an ergonomic deterministic fake implementing the same read contract;
  at least one boundary test uses the real SDK with a PSR-18/Guzzle fake.
- No automatic retry middleware; expose operation safety for future orchestration.
- Host identity verification/authorization is documented before subscriber-facing reads.

No writes, mapping tables, OAuth2, queues or webhook routes in this milestone.
