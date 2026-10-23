# Decision 005: host-owned administrative delivery restrictions

## Status

Proposed for the follow-up implementation after Task 07G. Task 07G exposes reads only;
it intentionally adds no DNC mutation contract.

## Why the stock API is insufficient

Mautic 7.2.1 stores DNC rows per contact and channel, but adding a different reason can
update the existing row for that channel. Its stock remove endpoint accepts only contact
and channel and performs an unconditional channel removal. `MANUAL` and free-text
comments identify neither an owner nor a particular command. A host read followed by
that removal is a time-of-check/time-of-use race: Mautic or another administrator may
replace the row between the read and delete. Deleting first and recreating unrelated
restrictions is also unsafe and can transiently permit delivery, lose provenance, or
overwrite concurrent work.

Therefore `releaseHostBlock` and bounce reset cannot be implemented safely through the
stock endpoint. A reason match, comments marker, or saved DNC snapshot is not sufficient
ownership evidence.

## Smallest safe model

Each administrative restriction is an independent record with a durable owner tuple:
named connection, remote contact ID, channel, host installation UUID, host restriction
UUID, and monotonically increasing command revision. The record also carries reason,
sanitized operator explanation, remote revision/token, creation and change timestamps,
and the last applied idempotency key. The effective suppression state is the logical OR
of all active restrictions. Releasing one host restriction removes only that record and
does not imply consent, contactability, subscription, or permission to send.

The package should store a connection-scoped command and audit record before delivery.
Block and release commands use a stable idempotency key derived from restriction identity
and revision. A newer durable revision supersedes older pending work, so a delayed block
cannot override a later release. Workers serialize one restriction aggregate, retain
attempts and sanitized outcomes, and never automatically retry an ambiguous side effect.
Retry after an unambiguous transient failure reuses the same idempotency key; an ambiguous
outcome requires an authoritative conditional read before any replay.

Bounce reset is a separate administrator-authorized command targeting explicit bounce
evidence. It must never release an unsubscribe, another administrative restriction, or a
different host's block. Every command checks host permission and exact connection/tenant
scope before revealing or changing state. Logs and routine diagnostics exclude contact
addresses, raw comments, tokens, credentials, and unrestricted payloads.

## Required minimal Mautic extension

A narrowly scoped Mautic-side extension must expose independent restriction resources,
not channel-wide replacement semantics. It must provide:

- create-or-confirm by `(host installation UUID, restriction UUID, revision)` with an
  idempotency key and a returned immutable restriction ID plus revision token;
- list/read of restriction ID, owner identity, channel, reason, active state and revision
  under normal Mautic contact permissions;
- conditional release of exactly one owned restriction using its immutable ID, owner
  tuple and expected revision/token in one atomic transaction;
- conditional bounce reset using an explicit bounce restriction/evidence ID and expected
  revision, never a channel-only delete;
- conflict responses when a record was changed, replaced or deactivated after the host's
  observation, and stable replay responses for an already-applied idempotency key;
- an append-only Mautic audit containing actor, owner, command revision and outcome while
  keeping explanations out of ownership decisions.

If Mautic or another administrator changes or replaces a restriction, a stale conditional
command fails without changing the replacement. If the extension cannot retain multiple
independent active restrictions, it must maintain an equivalent ownership ledger and
atomically recompute the combined DNC projection; it must never expose a window where one
remaining restriction is ineffective. The host package may add public administrative
write methods only after this atomic contract has live acceptance evidence.
