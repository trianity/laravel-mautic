# Decision 002: transactional contact outbox

## Status

Accepted for Task 05 and finalized by Tasks 05A, 05B and 05C.

## Transaction and dispatch boundary

The host explicitly records an immutable contact command while its own database
transaction is open. The package outbox must use that same Laravel database connection;
cross-database atomicity is not claimed. A rollback removes the command. An after-commit
callback only attempts queue dispatch, and deliberately swallows dispatch failure because
the durable row is the source of truth. A bounded recovery poller redispatches eligible
rows, so a process crash between commit and queue push is recoverable.

Every command has a host-supplied idempotency key, a byte-exact connection/local
reference aggregate, a positive monotonically increasing host revision, an explicit
create or update operation, and an allowlisted field payload. Unique operation identity
and aggregate revision constraints make repeated recording idempotent and reject
conflicting reuse.

## Serialization and crash behavior

A database lease keyed by the SHA-256 aggregate identity serializes work for one
connection/contact across queue workers. Commands are delivered sequentially by
revision: a command may start only after every lower stored revision completed. Partial
field changes are not coalesced or discarded, and create-before-update is an explicit
prerequisite. Pending, delayed, failed, blocked and ambiguous lower revisions all block
later work. The operator must retry or reconcile that lower command before the sequence
can continue. Recovery polling computes the lowest unresolved revision per aggregate
before applying its bounded limit, so a blocked aggregate cannot starve unrelated work.
The existing unique `(aggregate_hash, revision)` index supports this grouped head query;
the status/due-time and connection/status indexes support its remaining filters.

The published migration set contains only the complete canonical 020000 create
migration. It creates the commands, aggregate locks, operator audits and OAuth
connection-block tables and drops them in reverse order on rollback. It contains no
schema-detection, correction or migration-progress machinery.

The package is unreleased, so historical Task 05 development schemas have no upgrade
contract. Disposable development installations that ran earlier stubs must recreate the
package outbox schema before applying the canonical migration. The package does not
automatically drop or reset any host database, and `migrate:fresh` must not be run
against a host application database or production database copy.

The lease must exceed the host-declared complete-operation budget, which must cover each
individual request timeout. This sizing reduces expected expiry but is not remote
fencing: OAuth refresh, paginated preflight and mutation requests can together outlive
a lease, and Mautic provides no conditional-write token that prevents an expired worker
from later writing remotely. Every expired `processing` operation therefore becomes
`ambiguous`, including an update. It is never automatically retried, and its unresolved
revision blocks newer delivery. The multi-process recovery test proves this local safety
protocol; it does not claim exactly-once delivery or guaranteed remote ordering against
an uncooperative in-flight request. No database transaction remains open over HTTP.

## Failures and operator recovery

Throttling, remote-server and idempotent update transport failures use bounded
deterministic exponential backoff. The configured maximum caps package-calculated
backoff only; a valid numeric `Retry-After` is a server-directed minimum and may exceed
that cap. Validation, missing update targets and exhausted attempts are retained.
Read-only create preflight failures may retry before a mutation is sent. Create 5xx,
timeouts, transport loss, malformed success, known duplicates and unexpected mutation
responses are ambiguous and never retried automatically.

After a create returns, its remote ID and `remote_succeeded` stage are persisted before
claiming the mapping. Mapping or later local-persistence failure retains that ID as
reconciliation evidence when storage remains available. If the first evidence write
fails, the processor carries the returned ID into its owned ambiguity transition. If
storage remains unavailable or the process dies first, the row remains `processing`;
expiry applies the same conservative ambiguity protocol. This unavoidable crash window
is not an automatic-retry signal. `retryFailed()` cannot retry ambiguity.

OAuth token persistence uncertainty and reauthorization requirements create a durable,
byte-exact connection block. Other pending commands on that connection do not start.
After external token reconciliation or host-authorized OAuth completion, an operator
calls `resumeAuthentication()` with actor and reason; the block is removed, its blocked
commands return to pending, and the decision is audited. Token-endpoint throttling,
transient server failure and network failure retain their typed retry metadata.

Stored diagnostics contain only enumerated failure kind, HTTP status and package-owned
reason code; upstream messages, payloads and credentials are not persisted. Operator
retry is permitted only for terminal non-ambiguous failures. An ambiguous create needs
an explicit audited reconciliation decision: claim the discovered remote ID and finish,
or confirm absence before making it pending again.

An ambiguous update has two focused decisions: confirm that it was applied, completing
the revision, or authorize replay of the same retained command. Both require an active,
matching contact mapping and an explicit trusted operator attestation that the previous
worker/request has terminated or otherwise cannot mutate Mautic. Observing the desired
remote values is insufficient because Mautic supplies no request fence. State and audit
commit in one database transaction; conditional transitions make repeated or competing
decisions fail without a second audit. Later revisions resume only after the reconciled
revision completes (immediately for confirmation, after delivery for replay).

## Database support

SQLite tests prove transactional recording, dispatch-gap recovery and lease state
transitions. Separate PHP processes share one file-backed SQLite database for both the
duplicate-worker test and the controlled in-flight lease-expiry test; these are not
memory fakes. SQLite still serializes writes and does not reproduce InnoDB row-lock
scheduling. The opt-in disposable MariaDB suite verifies the final schema and shared
lease behavior on InnoDB. Production hosts must run workers against a shared durable
database; an in-memory repository or process-local mutex is insufficient.
