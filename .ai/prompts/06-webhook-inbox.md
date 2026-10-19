# Prompt 06 — Verified webhook inbox and inbound sync

Read AGENTS.md, .ai/STATE.md, .ai/PROJECT.md and docs/ARCHITECTURE.md first.
Inspect the installed SDK and current tests. Implement only this milestone using
Pest TDD, explicit types and model-independent contracts. Preserve PHP 8.4+ and
Laravel 12/13. Run focused tests, then composer check; report checks and limitations.
Update README, CHANGELOG and .ai/STATE.md with actual implemented behavior.
Do not tag, publish, call production Mautic or change host applications/upstream repos.

Add opt-in webhook ingress and a durable inbox, then process allowed inbound events
through the Contact mapping and ownership policies. Verify actual event payloads
and signature algorithm against the supported Mautic version.

Acceptance criteria:
- Verify the raw request body using the connection-specific secret and constant-time
  comparison. Signature is base64 HMAC-SHA256 if confirmed by the target server.
- Reject missing/invalid signature before processing. Handle invalid JSON, body
  bounds, unknown events, multiple event types and multiple items per delivery.
- Persist durably before acknowledging success; report persistence failure so the
  sender can retry. Processing is asynchronous and recoverable after restart.
- Separate delivery deduplication from semantic event identity. Do not assume every
  event has a UUID or timestamp. Preserve legitimate repeated identical events.
- Signature proves authenticity, not freshness: document replay limits and chosen
  retention/deduplication strategy instead of claiming exactly-once delivery.
- Handle out-of-order events, duplicates, consent/DNC distinctions and per-connection
  mapping; prevent inbound/outbound loops using ownership and change detection.
- No echo writes from generic Eloquent observers; no full customer payload logging.
- Tests cover invalid signatures, batches, duplicates, stale events, loops, tenant
  isolation, inbox failure and reprocessing after a crash.

No automatically exposed route unless explicitly enabled/configured by the host.
Document CSRF exemption and host routing requirements narrowly for this endpoint.

===

## Task 06A — Correct the cross-version route registration test

Fix the CI failure introduced by Task 06.

Both Laravel 12 jobs fail at tests/Feature/WebhookInboxTest.php:319:

    ->and(app('router')->getRoutes()->count())->toBe(0);

The actual route count is 2. Both Laravel 13 jobs and the Pint/Larastan
job pass.

The package must not automatically register webhook routes. This does
not require the entire Laravel/Testbench application to have zero routes.

### Required correction

1. Remove the global zero-route assertion from the delete-event test.
   Keep its assertion that the mapping becomes RemoteContactState::Deleted.
   Rename the test to describe its remaining responsibility.

2. Add a dedicated provider test proving that registering and booting
   MauticServiceProvider does not add or modify application routes.

   Use an isolated Testbench setup where this provider has not already
   been registered automatically. Register a representative host route,
   capture the route definitions, register and boot the package provider,
   and compare the resulting route definitions with the baseline.

   Compare meaningful route attributes, including methods, URI, name,
   action and middleware, rather than only route counts.

   Ensure the test would fail if the package provider started registering
   an automatic webhook endpoint.

3. Do not:
   - Replace the expected count with 2.
   - Add Laravel-version-specific expected counts or conditionals.
   - Remove framework routes to satisfy the assertion.
   - Skip the test on Laravel 12.
   - Change runtime webhook behavior or weaken the no-automatic-route
     requirement.

### Validation

Run the focused tests and composer check.

Verify compatibility with the existing CI matrix:
- PHP 8.4 / Laravel 12 / Testbench 10 / Pest 4.
- PHP 8.5 / Laravel 12 / Testbench 10 / Pest 4.
- PHP 8.4 / Laravel 13 / Testbench 11 / Pest 5.
- PHP 8.5 / Laravel 13 / Testbench 11 / Pest 5.

Keep the existing workflow matrix unchanged unless an independently
verified configuration defect requires correction.

Do not modify vendor, commit composer.lock, or add compatibility
infrastructure for this test correction.

Update .ai/STATE.md with the cause, correction and actual validation
results. Distinguish executed checks from checks awaiting remote CI.

Do not publish a release or begin Task 07.