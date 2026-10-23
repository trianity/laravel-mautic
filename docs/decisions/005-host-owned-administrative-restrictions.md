# Decision 005: stock administrative delivery restrictions

## Status

Accepted for the current product scope. Independent host-owned restrictions and a
Mautic extension are deferred; the extension is not a prerequisite for the public
restriction-write API.

## Decision

The stock Mautic REST API is sufficient for the currently accepted, explicit
administrative operations:

- set the contact's email-channel DNC state to the manual reason through
  `POST /api/contacts/{id}/dnc/email/add`; and
- explicitly release a restriction through
  `POST /api/contacts/{id}/dnc/email/remove`.

The package names these operations `blockEmail` and `releaseEmailRestriction`. The
release name deliberately does not imply host ownership. Both are immediate commands;
they are not automatic model synchronization and are not delivered through the contact
outbox.

The block command carries a required human explanation and a stable UTC
`recorded_at` marker. The marker is diagnostic only. It is not an ownership credential,
remote idempotency key, Mautic `dateAdded` value or conditional-write token. Mautic may
sanitize the submitted comments, so callers inspect the normalized resulting state and
the explicit exact-comments observation instead of assuming byte-exact storage.

## Accepted stock-API semantics

Mautic 7.2.1 represents the effective DNC state by contact and channel. Adding a
different reason can update the existing row, while adding the same manual reason may
retain its original metadata. An existing unsubscribe is not replaced by a manual
reason. The remove endpoint accepts only contact and channel; it has no expected reason,
record ID, owner or version condition. Its implementation removes one selected matching
row and therefore is not a promise that every independently represented restriction for
that channel was removed.

The package verifies the state embedded in the mutation response and performs at most
one explicit read when that embedded contact cannot establish the result. It reports
the observed outcome, including a retained pre-existing restriction, a remaining
restriction, or no matching restriction. A verification failure after a successful
request remains distinct from proof that no mutation occurred. Side-effectful commands
are never automatically retried, and repeated calls are not proof of remote exactly-once
processing.

A preflight read would not make a subsequent write atomic, so it is not used as an
ownership check. Channel-level release may affect an unsubscribe, bounce or
administrative restriction. Successful release does not establish consent,
subscription, contactability or permission to send. Hosts must authorize the operation
and make any required consent decision independently.

## Deferred stronger ownership model

Some applications may later require several independently owned restrictions, durable
command delivery or conditional release of exactly one host-owned record. The stock API
cannot provide those guarantees: free-text comments, a reason match and a saved DNC
snapshot are not ownership evidence, and read-then-remove has a time-of-check/time-of-use
race.

A future Mautic extension could expose immutable restriction IDs, owner tuples,
revisions, idempotency keys, conditional release and an append-only audit. Alternatively,
an equivalent Mautic-side ownership ledger would need to recompute the effective DNC
projection atomically without briefly disabling a remaining restriction. This design is
preserved as a possible future capability, not presented as implemented and not a
current blocker.
