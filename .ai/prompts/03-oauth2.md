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

===

## Task 03A — Correct OAuth2 lifecycle edge cases

This is a corrective continuation of Task 03. Inspect the current implementation
and preserve completed functionality. Do not start Task 04.

Read AGENTS.md, .ai/STATE.md, docs/ARCHITECTURE.md and the existing OAuth2 tests.
Verify the findings below against the actual source and installed Mautic SDK.
Use Pest TDD: reproduce each issue before implementing its correction.

### 1. Bind authorization state to its initiating context

Current state validation covers the connection, expiry and single use, but does
not bind the callback to the browser flow that initiated authorization.

Introduce an explicit, opaque authorization-context binding supplied by the host.
Keep the package independent of User models, guards and request/session globals.

Requirements:
- Bind state to the named connection and initiating context.
- The host must obtain the callback context from trusted server-side state;
  accepting it solely from callback query parameters is insufficient.
- Validate the binding before consuming state or exchanging the authorization code.
- Preserve atomic single-use consumption and expiry.
- Reject missing or mismatched context without exchanging the code or modifying tokens.
- Update the README with complete initiation/callback examples, including the host's
  responsibility for authorization and trusted context storage.

Test correct, missing, mismatched, expired, cross-connection and replayed contexts.
Include concurrent attempts to consume the same state.

### 2. Prevent stale token writes after lock expiry

The refresh lock can expire while its original owner is still working. Another
worker may acquire the lock, but the original worker can subsequently overwrite
newer token data. Callback token persistence also bypasses refresh coordination.

Design a coherent concurrency protocol for every token-writing path.

Requirements:
- Prevent a stale lock owner from overwriting a newer token generation.
- Coordinate callback authorization and background refresh for the same connection.
- Use an ownership/version-aware persistence mechanism or another demonstrably safe
  protocol; owner-checked lock deletion alone does not protect token writes.
- Ensure validation and persistence are atomic where required.
- Validate lock settings and their relationship to the bounded token request timeout.
  A configuration check alone is not a substitute for stale-writer protection.
- Define behavior when the remote token rotation succeeds but local persistence fails
  or lock ownership is lost. Do not blindly repeat a potentially consumed refresh token.
- Preserve connection isolation, bounded waiting and safe lock release.

Add deterministic tests for:
- lease expiry while the original refresh is still running;
- a competing worker acquiring the expired lock;
- the original worker returning after a newer token has been persisted;
- callback completion racing with refresh;
- token persistence failure after remote rotation.

Extend the existing real-process/shared-database tests where necessary. State
explicitly which guarantees are proved on SQLite and which remain unverified on
MariaDB or other supported databases. Avoid timing-only tests without coordination.

### 3. Preserve authentication failures through the public API boundary

The upstream SDK catches exceptions raised by ManagedOAuthAuth and converts them
to generic error arrays. This can turn OAuthReauthorizationRequired into a transport
failure, or classify a contact write that was never sent as AmbiguousMutationOutcome.

Requirements:
- Preserve a typed, inspectable distinction between:
  - authorization required;
  - refresh lock timeout;
  - token endpoint failure;
  - failure before sending the contact request;
  - ambiguous failure after a contact mutation may have been sent.
- Carry that distinction safely through the SDK integration and normalized services.
- Do not recover exception types by matching human-readable exception messages.
- Do not expose credentials, tokens, authorization codes or unrestricted payloads.
- Do not automatically replay contact mutations.
- Preserve existing Basic Auth and genuine ambiguous-mutation behavior.

Exercise the real SDK with fake HTTP through contactReads() and contactWrites(),
rather than testing OAuthTokenManager alone.

Test missing authorization, rejected refresh, lock timeout and token endpoint
unavailability. Assert that no contact request was sent when authentication failed,
and that the resulting failure is not an ambiguous contact mutation.

### 4. Classify token endpoint failures correctly

HttpOAuthTokenEndpoint currently treats every refresh HTTP 4xx response as requiring
reauthorization, including HTTP 429.

Requirements:
- Classify failures using both HTTP status and a safely parsed OAuth error code.
- Distinguish invalid/revoked grants from client configuration errors, throttling
  and transient endpoint failures.
- Preserve HTTP status and safe Retry-After metadata where applicable.
- Do not overwrite valid stored credentials because of a transient endpoint failure.
- Keep requests bounded and avoid automatic retry loops.
- Handle malformed, empty and unexpected error responses without exposing their bodies.

Add tests for invalid_grant, invalid_client, HTTP 429 with Retry-After, HTTP 5xx,
network failure and malformed error responses.

### 5. Normalize instance and API base URLs consistently

ConnectionConfig accepts URLs with an existing /api suffix, but OAuth endpoint
construction currently appends /oauth/v2/... directly to that URL.

Requirements:
- Define one canonical normalization rule for the instance root and REST API base.
- Construct authorization and token URLs from the instance root.
- Preserve subdirectory installations and existing Basic Auth behavior.
- Reuse the same normalization logic across authorization, exchange and refresh.
- Preserve HTTPS validation, explicit local HTTP opt-in, and rejection of credentials,
  query strings and fragments in instance URLs.

Test root, trailing-slash, subdirectory, /api and /api/ configurations. Assert the
actual authorization URL and token request URL, not only configuration getters.

### Completion criteria

- All five corrections have focused regression coverage.
- Existing contact read/write, batch, segment and Basic Auth tests remain green.
- PHP 8.4+ and Laravel 12/13 compatibility is preserved.
- Run composer check and verify the GitHub compatibility CI after pushing when authorized.
- Update README, CHANGELOG, architecture documentation and .ai/STATE.md to describe
  implemented behavior, configuration changes and verification limits accurately.
- Do not lower Larastan's level, weaken tests or suppress errors to pass checks.
- Do not call production services, modify upstream repositories, tag releases,
  publish to Packagist or implement Task 04.
- Report the corrections, test results and any remaining real-server validation needs.