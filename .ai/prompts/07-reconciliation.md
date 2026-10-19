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

===

## Task 07A — Reconciliation correctness and live-test reliability

Correct the six findings below in the existing Task 07 implementation.

Read AGENTS.md, .ai/STATE.md, the original Task 07 prompt, and
docs/decisions/004-reconciliation.md first. Verify each finding against
the current code and installed SDK before changing it.

Use TDD with focused behavioral regression tests. Stay within Task 07.

### 1. Prepare OAuth token persistence before remote rotation

The live OAuth test currently refreshes the token before exclusively
creating and securing its output file. If file creation or chmod fails,
the old refresh token may already be invalid and the new token is lost.

Before sending the refresh request:
- Exclusively create the destination file.
- Restrict and verify its permissions.
- Keep the prepared handle available for persistence.
- Fail without any HTTP request if preparation fails.

After receiving the rotated token, persist its complete contents.
Verify the exact byte count and successful flushing; a positive partial
write is insufficient.

If persistence fails after remote rotation, report an explicit uncertain
token-persistence outcome. Do not automatically repeat the refresh or
expose tokens in exceptions, assertions, console output or logs.

Document the unavoidable crash window between remote rotation and durable
local persistence. Do not claim that precreating a file removes it.

Add offline tests for existing/unwritable output paths, permission
preparation failure, partial/failed writes, and successful persistence.
Assert that preparation failures send no refresh request.

### 2. Reserve reconciliation requests atomically before HTTP

The current next_page_at check and post-request update do not coordinate
concurrent invocations. Multiple processes can read the same eligible
checkpoint and send overlapping requests.

The current expected-checkpoint predicate also uses only connection,
scan ID and cursor. Those values can remain unchanged while another
worker records a failure or completion.

Implement shared-database request coordination:
- Atomically reserve eligible work before sending HTTP.
- Cover both high-watermark and page requests.
- Persist the scheduling decision before the request.
- Associate result commits with the current reservation/generation.
- Reject stale success and failure updates.
- Provide bounded crash recovery for abandoned reservations.
- Ensure restart cannot let an old scan overwrite a replacement scan.
- Keep database transactions and row locks out of network calls.

Use the smallest focused protocol that satisfies these requirements.
Do not introduce a generic distributed workflow framework.

Add controlled multi-process tests using a shared database for:
- Two simultaneous invocations with only one eligible request.
- An abandoned reservation and its recovery.
- A delayed old result after recovery or restart.
- A late failure that cannot overwrite newer completion.
- Durable request spacing and accurate request accounting.

Do not describe in-memory or sequential tests as process-concurrency
evidence.

### 3. Preserve Retry-After throughout reconciliation

Propagate current-response Retry-After metadata through the SDK adapter,
typed exceptions and durable scheduling.

A valid server-directed delay is a minimum. For example, a 429 response
with Retry-After: 3600 must prevent another reconciliation request before
that delay expires, even if the local minimum interval is one second.

Reuse the package's established response-metadata handling where
appropriate. Do not read an uninitialized SDK response or reuse headers
from a previous request after a transport failure.

Preserve applicable metadata from typed transient OAuth failures too.
Do not silently turn token-persistence uncertainty or reauthorization
requirements into ordinary transient retries.

Add tests for watermark and page throttling, delays exceeding local
settings, malformed metadata, and a prior response followed by a
transport failure.

### 4. Require reliable evidence of scan completion

SdkConnectionReconciliationSource currently treats an empty contact list
as a zero watermark even when total is positive. Page responses discard
total, while ContactReconciler treats any short page as completion.

Validate response consistency and preserve enough pagination metadata
to establish whether the requested range is exhausted.

Requirements:
- A positive total with an empty watermark result must not become a
  successful empty scan.
- A short page that reports additional remaining contacts must not
  silently complete the scan.
- Validate contact IDs, requested bounds, ordering and forward progress.
- An empty or incomplete response must never imply remote deletion.
- Resume from the last atomically committed cursor after failure.

Verify actual installed SDK/Mautic query semantics. Do not assume that
every response must fill the requested limit, or invent unsupported
server guarantees.

Add SDK-boundary and reconciler tests for contradictory totals,
short non-final pages, valid final pages, empty results, and no-progress
responses. Ensure failures do not falsely advance or complete the scan.

### 5. Make mapping inventory selection byte-exact before limiting

DatabaseMappingInventory currently filters connection using ordinary
text equality. Under a case-insensitive MariaDB collation, names such
as "main" and "Main" can share the selected result set.

With equal remote IDs, this can resolve the same mapping twice and
cause an apply batch to fail after its first mutation.

Use collation-independent, byte-exact connection selection before
ordering and limiting. Preserve the established identity semantics for
case, accents and trailing spaces.

Do not merely filter after LIMIT, globally change database collation,
or add duplicate suppression that leaves incorrect pagination intact.

Retain one canonical migration for the current unreleased schema.
If schema/index changes are necessary, update it directly; do not add
development-version upgrade migrations or compatibility bridges.

Add SQLite and opt-in MariaDB coverage with distinct connection names
and overlapping remote IDs. Verify dry-run results, cursor progression,
and exactly one audited application per intended mapping.

### 6. Match live webhook evidence structurally

The live webhook test currently searches the raw body for an ID
substring. Contact 123 can incorrectly match 1234, and an unrelated
nested ID or event can also satisfy the test.

After verifying the signature over the exact raw body:
- Parse the JSON.
- Locate the expected contact-update event.
- Require an exact contact ID match.
- Verify the dedicated fixture and the expected test modification.
- Do not accept the earlier create event as update evidence.

Handle incomplete trailing JSONL capture records while the capture file
is being appended, without treating partial data as valid evidence.

Extract only the focused parsing/matching logic needed for offline
regression tests. Cover prefix-colliding IDs, unrelated nested IDs,
wrong event types, the pre-update value, valid batched updates, invalid
signatures, and partially written capture records.

### Validation and completion

Run focused tests and composer check, including Pint and Larastan level 8.
Keep the PHP 8.4/8.5 and Laravel 12/13 CI matrix intact.

Run opt-in MariaDB tests only against the existing dedicated disposable
test database when configured.

Do not run live HTTP tests as part of this correction. Verify that the
live suite remains skipped without its explicit opt-in and sends no
network requests.

Update README, decision 004, HOST_MIGRATION.md where behavior changes,
changelog, and .ai/STATE.md. Describe implemented guarantees and actual
limitations precisely.

Report each correction, regression coverage, checks executed, results,
and checks not executed. Keep live Mautic compatibility acceptance
pending until separately authorized and actually demonstrated.

Do not modify vendor, commit credentials, publish a release, introduce
development compatibility bridges, or begin Task 08.

===

## Task 07B — Preserve reservation ownership during handoff

Fix the remaining race in DatabaseReconciliationStore::reserve().

Read AGENTS.md, .ai/STATE.md and the current reconciliation implementation
and concurrency tests first.

### Finding

reserve() conditionally updates the checkpoint with a newly generated
owner and incremented generation, then returns:

    return $this->get($expected->connection);

That unqualified read can return another worker's reservation.

Example:
1. Worker A successfully reserves generation 1 with owner A.
2. A pauses after the UPDATE but before the following SELECT.
3. A's reservation expires.
4. Worker B reserves generation 2 with owner B.
5. A resumes and reads B's checkpoint.
6. A can now issue a request and submit a result using B's credentials
   for the local reservation, defeating stale-result rejection.

The current abandoned-reservation test pauses after reserve() returns,
so it does not cover this handoff gap.

### Required correction

Ensure reserve() can return only the reservation created by that
specific invocation.

Bind the returned value to the exact connection, scan ID, newly generated
owner, generation and expiry established by its successful UPDATE.

If another worker or restart has replaced the reservation before the
handoff completes:
- Return no reservation, or use an explicit existing lost-reservation
  outcome that the caller handles safely.
- Do not return the replacement worker's checkpoint as owned work.
- Do not issue HTTP under another worker's reservation.
- Do not clear, release or modify the replacement reservation.

Use a focused solution, such as an ownership-qualified read with
validation or a short atomic update/read transaction. Do not hold
database transactions or row locks during HTTP.

Review ContactReconciler's handling of an unsuccessful handoff. Returning
a current checkpoint for reporting must not authorize a network request.

Do not claim that this fix remotely fences an HTTP request after a
later lease expiry. Its purpose is to prevent ownership substitution
and preserve correct local result attribution.

### Regression test

Add a deterministic test with separate PHP processes sharing a real
file-backed database.

Place a controlled barrier specifically after A's successful reservation
UPDATE and before its reservation readback:

1. Pause A at that boundary.
2. Advance the shared clock beyond A's expiry.
3. Let B acquire a replacement reservation and keep it active.
4. Resume A.
5. Verify A does not receive B's reservation or send HTTP using it.
6. Verify A cannot commit success or failure against B's reservation.
7. Verify B's ownership and checkpoint remain intact and B can finish.

Use narrow test instrumentation; do not add public runtime hooks solely
to orchestrate the test.

Also cover a restart replacing the scan during the same handoff gap.
Retain existing ordinary-race, abandoned-reservation and stale-result
tests.

### Validation and scope

Run focused reconciliation/concurrency tests and composer check,
including Pint and Larastan level 8.

Keep the PHP 8.4/8.5 and Laravel 12/13 CI matrix unchanged. Run existing
opt-in MariaDB tests only if their dedicated configuration is available.

No new migration, compatibility bridge, framework or live HTTP test is
required. Do not modify vendor, publish a release or begin Task 08.

Update .ai/STATE.md and the decision record where necessary. Report the
root cause, correction, regression coverage, executed checks and results.
Keep live Mautic compatibility acceptance pending.