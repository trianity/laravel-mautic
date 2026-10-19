# Decision 002: transactional contact outbox

## Status

Accepted for Task 05.

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
connection/contact across queue workers. The lease duration must exceed the configured
Mautic request timeout. The worker reloads state after acquiring the lease, suppresses
duplicate jobs, and marks a command superseded when a higher revision exists. This lets
newer state follow an in-flight older update without allowing an old queued job to run
after the newer revision.

The command is durably marked `processing` before a remote call. A crashed idempotent
field update may be retried after its lease expires. A crashed create without a durable
mapping becomes `ambiguous` and is never automatically retried. If the mapping was
already committed before the crash, recovery uses the mapped remote ID and performs an
idempotent update instead of another create. Locks are leases rather than transactions
held across HTTP; no database transaction remains open during a remote request.

## Failures and operator recovery

Authentication, throttling, remote-server and idempotent update transport failures use
bounded deterministic exponential backoff. A numeric `Retry-After` is honored within
the configured maximum delay. Validation, malformed responses, missing update targets
and exhausted attempts are retained as terminal failures. Create timeouts, transport
loss, known duplicates and expired create processing without a mapping are retained as
ambiguous outcomes.

Stored diagnostics contain only enumerated failure kind, HTTP status and package-owned
reason code; upstream messages, payloads and credentials are not persisted. Operator
retry is permitted only for terminal non-ambiguous failures. An ambiguous create needs
an explicit audited reconciliation decision: claim the discovered remote ID and finish,
or confirm absence before making it pending again.

## Database support

SQLite tests prove transactional recording, dispatch-gap recovery and lease state
transitions. A separate multi-process file-backed SQLite test proves shared storage, but
SQLite serializes writes and does not reproduce InnoDB row-lock scheduling. The opt-in
MariaDB suite uses a disposable database with case-insensitive default collation and is
required before host integration claims. Production hosts must run workers against a
shared durable database; an in-memory repository or process-local mutex is insufficient.
