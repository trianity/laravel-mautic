# Prompt 03 — OAuth2 lifecycle with durable tokens

Read AGENTS.md, .ai/STATE.md, .ai/PROJECT.md and docs/ARCHITECTURE.md first.
Inspect the installed SDK and current tests. Implement only this milestone using
Pest TDD, explicit types and model-independent contracts. Preserve PHP 8.4+ and
Laravel 12/13. Run focused tests, then composer check; report checks and limitations.
Update README, CHANGELOG and .ai/STATE.md with actual implemented behavior.
Do not tag, publish, call production Mautic or change host applications/upstream repos.

Implement opt-in OAuth2 alongside Basic Auth, behind an authentication abstraction.
First verify the grants/refresh behavior of the target Mautic version and installed
SDK. Design the authorization handoff separately from background API use.

Acceptance criteria:
- Explicit encrypted durable token repository contract, connection-scoped records,
  expiration handling and configurable storage; no secrets in logs or config cache.
- OAuth callback state validation, one-time consumption, expiry and connection binding.
- No interactive redirects from queue jobs or backend API calls.
- Distributed refresh locking, re-read after acquiring lock, atomic persistence of
  rotated access/refresh tokens before release, and bounded refresh failure handling.
- Tests for simultaneous refresh, stale token objects, rotation, expiry, revoked
  refresh tokens, invalid state and connection isolation. State exactly what any
  concurrency test proves and use an appropriate shared-store integration test.
- Invalid credentials do not enter an unlimited retry loop. Basic Auth remains usable.
- No assumption that Mautic supports client_credentials.

Document setup and recovery without asking for production credentials. Any optional
migrations must be explicitly publishable and namespaced; never run them implicitly.
