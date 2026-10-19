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

The page's observations, findings and cursor commit in one local transaction. A failed
request does not advance the cursor. A process failure before that transaction causes
the same page to overlap on retry; unique `(scan_id, remote_id)` observations and
`(scan_id, remote_id, kind)` findings deduplicate the replay. No host business effect is
performed by the scan. Configured page size, pages per invocation and minimum request
interval bound API load. Restarting a completed or abandoned scan is explicit.

An empty or incomplete page never marks a mapping missing, deletes a host contact or
replaces segment membership with an empty set. The remote scan records only unmapped
remote IDs and non-active mapping observations. It stores no contact fields, email,
segments, DNC records or webhook payload.

## Mapping recovery

Missing-remote mapping recovery is a separate bounded workflow. It keyset-pages only
active local mappings and performs an authoritative contact lookup for each. Read
failures stop the batch; only a normalized `ContactNotFound` is a missing candidate.
Dry-run is the default. Applying requires the explicit `--apply-missing` switch plus a
bounded actor and reason code.

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
and OAuth refresh have additional environment gates. OAuth refresh requires an absolute
mode-0600 output file because the server may rotate the refresh token. Webhook proof
requires a local JSONL capture containing the exact base64 body and signature. Skipped
optional probes are not compatibility evidence.
