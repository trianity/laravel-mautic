# Decision 004: bounded reconciliation and operational proof

## Status

Accepted for Task 07.

## Stable scan boundary

Reconciliation is an explicit read workflow, not a model observer. A named connection
has one durable current checkpoint containing a random scan ID, a captured maximum
remote contact ID, the last committed remote ID, request counts and sanitized failure
classification. Pages use the SDK custom query with `id > cursor`, `id <= high water`
and ascending ID order. Contacts created above the captured high water belong to the
next explicitly started scan.

Before either the watermark or a page request, one conditional database update reserves
the request with a random owner, incremented generation and bounded expiry. It also
increments the durable request count and advances the local minimum request time before
HTTP. No row lock or transaction crosses the network call. Success and failure commits
require the same scan, generation and owner. Expiry permits crash recovery; delayed old
results, late failures after completion and results from a restarted scan are rejected.
The reservation lifetime must exceed every configured Mautic request timeout.

The page's observations, findings and cursor commit in one local transaction. A failed
request does not advance the cursor. A process failure before that transaction causes
the same page to overlap on retry; unique `(scan_id, remote_id)` observations and
`(scan_id, remote_id, kind)` findings deduplicate the replay. No host business effect is
performed by the scan. Configured page size, pages per invocation and minimum request
interval bound API load. Restarting a completed or abandoned scan is explicit.

Numeric current-response `Retry-After` metadata extends the durable next-request time and
is never capped by the local interval. The SDK adapter reads headers only when the current
result proves an HTTP error; a later transport failure cannot inherit earlier headers.
Transient OAuth token failures preserve the same delay. Token-persistence uncertainty,
reauthorization and non-transient token endpoint failures block the scan.

An empty or incomplete page never marks a mapping missing, deletes a host contact or
replaces segment membership with an empty set. The remote scan records only unmapped
remote IDs and non-active mapping observations. It stores no contact fields, email,
segments, DNC records or webhook payload.

The SDK range total is retained with every page. A short page is not final when that
total reports more matching rows. Positive totals with empty results, totals smaller
than returned rows, invalid bounds, duplicates, non-ascending IDs and no-progress
responses are typed failures and leave the committed cursor unchanged.

## Mapping recovery

Missing-remote mapping recovery is a separate bounded workflow. It keyset-pages only
active local mappings and performs an authoritative contact lookup for each. Read
failures stop the batch; only a normalized `ContactNotFound` is a missing candidate.
Dry-run is the default. Applying requires the explicit `--apply-missing` switch plus a
bounded actor and reason code.

Inventory selection uses the canonical mapping table's byte-exact connection hash before
remote-ID ordering and `LIMIT`, with one look-ahead row for correct cursor completion.
This preserves case, accents and whitespace under case-insensitive MariaDB collations.

The default apply path requires identity and reconciliation storage on the same database
connection. It changes the expected active mapping generation to `missing` and inserts
an operator audit in one transaction. A concurrent mapping change rejects the repair.
It never relinks, recreates or deletes either contact. Cross-database atomic repair is
not claimed; hosts using separate storage must supply a domain-specific alternative.

## Diagnostics and retained data

Diagnostics expose connection-scoped counts for mapping states, outbox states, inbox
states and current-scan finding kinds, plus the reconciliation checkpoint and OAuth
token health (`missing`, `valid`, `expiring`, `expired` or unavailable). They do not
decrypt tokens or return command payloads, contact fields, local identity values,
webhook bodies, credentials or remote error messages.

Observations, findings and audits are retained until the host applies its own documented
privacy and audit policy. The package does not automatically purge them and never resets
a host database. Task 07 has one canonical create migration and no compatibility bridge
for unreleased development schemas.

## Real-server boundary

`tests/Live` is outside the default PHPUnit suites. `composer check` therefore performs
no network requests. `composer test:live` still sends nothing until exact disposable,
loopback URL, fixture-prefix, local install path and server-version opt-ins pass.

The core live test creates a random dedicated contact, verifies Basic Auth, partial
PATCH, DNC reads and optionally manual segment commands, then re-reads the email and
prefix before deleting only that contact. Dynamic segment convergence, webhook capture
and OAuth refresh have additional environment gates. Before OAuth refresh, the output
file is exclusively created and verified as mode 0600. Complete rotated token JSON must
be fully written and flushed/fsynced; failure after rotation is explicitly uncertain.
There remains an unavoidable crash window between remote rotation and durable local
persistence. Webhook proof verifies the exact raw signature, parses complete JSONL
records and structurally matches the dedicated update event, contact, email and changed
field. Skipped optional probes are not compatibility evidence.
