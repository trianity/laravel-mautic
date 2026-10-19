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

===

## Task 05A — Correctness fixes for durable outbound synchronization

Review and correct the existing Task 05 implementation. Stay within
the outbound outbox scope; do not start Task 06.

Read AGENTS.md, .ai/STATE.md, the existing Task 05 prompt, and
docs/decisions/002-outbox-delivery.md first. Verify the findings below
against the current code and the installed Mautic SDK before changing
anything. Implement the corrections, add meaningful regression tests,
and update the decision record and project state.

### 1. Preserve partial changes and create prerequisites

DatabaseOutboxStore::begin() currently supersedes an older command when
any higher revision exists. Commands contain partial field changes,
so this can silently discard data.

Examples:
- Revision 1 changes firstname; revision 2 changes phone. Both changes
  must reach Mautic.
- Revision 1 creates the contact; revision 2 updates it. The create must
  establish the mapping before the update proceeds.

Define and implement explicit per-aggregate ordering. Prefer sequential
processing unless a coalescing strategy demonstrably preserves every
field operation and prerequisite.

A higher revision must not silently bypass an unresolved earlier
mutation. Document how failed, ambiguous, and delayed earlier commands
affect subsequent revisions and how an operator can resolve a blocker.

Add regression tests for disjoint partial changes, create followed by
update, out-of-order job delivery, and an unresolved earlier command.

### 2. Handle lease expiry without claiming remote fencing

A database lease and ownership-checked local completion do not prevent
an expired worker from subsequently writing to Mautic.

The current lease validation compares the lease with an individual
request timeout, but one operation may include OAuth refresh, multiple
preflight requests, and the mutation itself.

Define a recovery protocol that accounts for the complete operation
and an uncertain in-flight remote request. Prevent a newer revision
from being delivered while an earlier remote mutation remains
unresolved.

Use ownership checks, operation budgets, renewal, or reconciliation
where appropriate. Checking ownership immediately before an HTTP call
alone does not fence an already-running remote request.

If Mautic cannot provide remote conditional writes or fencing, document
that limitation explicitly. Do not claim exactly-once delivery or
guaranteed remote ordering from local lease checks alone.

Add a deterministic multi-process or equivalent controlled integration
test covering lease expiry while a remote mutation is in flight.
Verify that recovery cannot automatically deliver a newer revision
that an older worker could then overwrite.

### 3. Read response metadata safely

SdkContactWriter now reads getResponseHeaders() when passing results
to SdkResponseBoundary. In the installed SDK, a first-request transport
failure can leave the underlying response property uninitialized.

Ensure response-header inspection cannot mask the original failure
with a PHP Error. Metadata must belong to the current request;
previous response headers must not supply a stale Retry-After value.

Inspect the installed SDK implementation and use a reliable approach.
Do not broadly suppress errors and turn failures into success.

Add tests through the actual SDK adapter for:
- A first update request failing with a connection error.
- A first create preflight request failing with a connection error.
- A successful response followed by a transport failure, proving that
  response metadata is not reused.

Assert the intended typed exception and outbox classification.

### 4. Preserve uncertain create outcomes

Distinguish failures before sending a mutation from failures after
a mutation may have reached Mautic.

In particular:
- A create returning HTTP 5xx may already have committed remotely.
  Do not automatically retry it without establishing that retry is safe.
- If remote creation succeeds but mapping persistence or subsequent
  local persistence fails, retain an ambiguous/reconciliation-required
  outcome rather than a generic retryable failure.
- Preserve the returned remote contact ID as recovery evidence when
  available.
- retryFailed() must not bypass the guarded recovery path for an
  uncertain create.
- A failed read-only preflight can remain eligible for bounded retry
  when no mutation was sent.

Track the delivery stage explicitly where necessary. Recovery must
remain safe if recording the outcome also fails or the worker crashes.

Keep operator reconciliation explicit and audited. Correct any
transaction or concurrency gaps exposed by these changes.

Add regression tests for create 5xx, successful create followed by
mapping persistence failure, operator retry restrictions, and safe
preflight retries.

### 5. Respect typed OAuth failure semantics

Do not classify every OAuthAuthenticationException as an ordinary
retryable authentication error.

Review the existing OAuth exception hierarchy and preserve its meaning:
- OAuthTokenPersistenceUncertain must stop automatic delivery until
  the token state has been reconciled.
- OAuthReauthorizationRequired must require the appropriate external
  authorization action instead of repeatedly retrying the command.
- Throttling and transient endpoint failures should retain their
  status and Retry-After metadata where available.

Define how a connection-level authentication blocker affects pending
commands and how delivery resumes after resolution. Reuse existing
OAuth contracts rather than introducing a competing token lifecycle.

Add regression tests for the relevant typed exceptions, including
uncertain token persistence and reauthorization requirements.

### 6. Honor Retry-After as a minimum delay

The current calculation caps Retry-After at maximum_delay_seconds.
This can retry earlier than the server permits.

Apply the configured cap to locally calculated exponential backoff,
not to a valid server-directed minimum delay.

For example, Retry-After: 3600 must not produce a retry after 300
seconds when maximum_delay_seconds is 300.

Handle malformed values safely and test both ordinary backoff and
a server delay exceeding the configured backoff cap.

### Validation and completion

Keep the normal test suite offline. No host application or live Mautic
instance is required for these corrections.

Run composer check, including Pint, Larastan level 8, and Pest.
Retain and extend the real shared-database concurrency coverage.

Run the opt-in MariaDB tests only against the dedicated test database
when its existing local configuration is available. Never fall back
to another database. Do not print, commit, or copy credentials into
prompts, documentation, test output, or fixtures.

If published migrations change, address upgrades for existing
installations as well as fresh installations.

Update docs/decisions/002-outbox-delivery.md and .ai/STATE.md with the
actual guarantees, remaining limitations, recovery behavior, and
validation results.

Report:
- The root cause and correction for each finding.
- The regression tests added.
- Checks actually executed and their results.
- Any remaining limitations or checks not executed.

Do not weaken tests to accommodate unsafe behavior. Do not publish a
release or start the next roadmap task.

===

## Task 05B — Targeted outbox recovery corrections

Correct the four findings below in the existing Task 05/05A implementation.
Stay within the outbound outbox scope. Do not start Task 06.

Read AGENTS.md, .ai/STATE.md, the previous Task 05 instructions, and
docs/decisions/002-outbox-delivery.md first. Verify each finding against
the current code before implementing it.

Use TDD with meaningful behavioral regression tests. Preserve the
conservative handling of uncertain remote mutations.

### 1. Prevent unsafe replay during migration

The Task 05A correction migration changes every legacy `superseded`
command to `pending`.

This is unsafe when a newer revision has already completed:

- Revision 1 sets firstname to "Older" and is superseded.
- Revision 2 sets firstname to "Newer" and completes.
- The migration makes revision 1 pending.
- Revision 1 can then overwrite the newer remote value.

Do not automatically replay historical commands across a completed
newer revision. Preserve their payloads and identity, but require
explicit reconciliation wherever replay could regress remote state.
Do not assume that partial payloads can safely be merged or discarded.

Define and document the migration policy for legacy superseded rows,
including cases with and without newer completed revisions.

Support both installations that have not run Task 05A's migration and
installations that have already run it. Editing an already-recorded
migration alone is insufficient. Add a forward migration where needed.
Do not automatically replay already-completed commands.

Add regression tests for:
- Superseded v1 plus completed v2 modifying the same field.
- Retained disjoint partial changes without blind historical replay.
- An installation already migrated by Task 05A.
- Fresh installation compatibility.

Assert resulting delivery behavior, not only column existence.

### 2. Provide audited recovery for ambiguous updates

Expired in-flight updates now become `ambiguous`, correctly blocking
later revisions. However, the public OutboxOperator API only supports
ambiguous-create reconciliation, while retryFailed() only accepts
`failed` commands.

Implement explicit, audited recovery for ambiguous updates.

Define supported operator decisions, such as confirming that the update
was applied or explicitly authorizing replay after reconciliation.
Specify their prerequisites and how subsequent revisions resume.

Mautic provides no remote fencing. A remote read alone cannot prove
that an old request will not finish later. Require an explicit trusted
operator attestation that the previous worker/request can no longer
mutate the contact before releasing this uncertainty barrier.

Do not invent an automatic quiescence check that the package cannot
actually guarantee. Document which facts the host/operator must establish.

Recovery must:
- Preserve aggregate ordering and mapping identity.
- Validate the current command state.
- Be safe under concurrent/repeated operator actions.
- Commit the state change and audit atomically.
- Avoid allowing generic retryFailed() to bypass ambiguity handling.

Keep the API focused; do not introduce a general workflow engine.

Add tests for applied-update reconciliation, explicitly authorized
replay, later-revision unblocking, invalid states, repeated/concurrent
decisions, and audit persistence.

### 3. Prevent recovery-poller starvation

recoverableOperationIds() currently selects pending commands ordered
by ID and applies its limit before excluding commands blocked behind
unresolved earlier revisions.

Example with recover(1):
- Contact A revision 1 is ambiguous.
- Contact A revision 2 is pending.
- Contact B revision 1 is pending.
- Every poll selects A revision 2, which cannot start.
- B never gets selected.

Make bounded recovery selection account for per-aggregate eligibility
before applying the limit, or implement an equally robust bounded
fairness strategy.

Exclude commands blocked behind unresolved earlier revisions while
retaining recovery of expired processing operations. Preserve due-time
and connection-block checks. Apply the same ordering semantics as the
processor.

Do not solve this by removing limits, scanning the entire table in PHP,
or relying on an ever-increasing poll limit.

Add regression tests using a small limit, including:
- An unrelated eligible contact behind blocked pending revisions.
- Delayed, failed, and ambiguous predecessor states.
- Expired processing recovery.
- Progress after the predecessor is resolved.
- Connection-level authentication blocking.

Consider query/index requirements for SQLite and MariaDB.

### 4. Retain a returned remote ID if its first persistence fails

After transport->create() returns a remote ID, recordRemoteSuccess()
may throw before persisting it. The outer failure handler currently
uses the reloaded record's remoteId, which can still be null even
though the returned ID is available locally.

Retain the returned ID through this failure path. If a subsequent
ambiguity write succeeds, persist that ID as reconciliation evidence.
Do not convert the outcome into automatic create retry.

Do not overwrite a concurrent recovery decision or attach evidence to
the wrong operation. Continue honoring ownership/state checks.

If storage remains unavailable or the process dies before evidence
can be saved, preserve the conservative expired-operation recovery
behavior and document that unavoidable limit.

Add a fault-injection regression test where:
- Remote creation returns a known ID.
- The first remote-success persistence attempt fails.
- The subsequent ambiguity persistence succeeds.
- The command remains ambiguous with that exact remote ID.
- Reprocessing does not send another create.

Exercise this through OutboxProcessor and a real test database with a
targeted failure mechanism, rather than testing a standalone helper.

### Validation and completion

Run composer check, including Pint, Larastan level 8, and Pest.
Keep existing real multi-process shared-SQLite tests passing.

Run the opt-in MariaDB fresh/upgrade tests when the existing dedicated
test database configuration is available. Include the new forward
migration in the applicable upgrade coverage.

Keep default tests offline. No live Mautic or host integration is needed.
Do not print or commit credentials, change vendor code, weaken checks,
publish a release, or begin Task 06.

Update the decision record, README where public recovery behavior
changes, changelog, and .ai/STATE.md.

Report:
- The correction and regression coverage for each finding.
- Migration behavior before and after Task 05A.
- Operator recovery prerequisites and remaining remote limitations.
- Checks actually executed, their results, and checks not executed.