# Decision 006: durable subscription delivery

## Status

Accepted for Task 07J.

## Identity and replay

The host records one accepted subscription with a byte-exact named Mautic connection,
local contact reference, stable event key and stable segment key. The event key identifies
one business acceptance, not an email address. Its SHA-256 scoped identity and a canonical
semantic hash make an identical replay idempotent; reuse with different contact fields,
revision, contact identity or segment intent is a conflict.

Contact identity remains the existing connection-scoped `LocalContactReference` mapping.
An active mapping produces an update command. With no mapping, the existing conservative
create path is used; exact-email discovery is not authorization to claim another contact.
The host supplies a positive contact revision so the existing outbox ordering and shared
contact lease remain authoritative.

The segment key is a host business key, distinct from Mautic's display name and alias.
A connection/key association stores the verified remote segment ID and definition hash.
An existing-only target may name a remote ID explicitly. A create-if-absent target carries
an explicit published, empty-filter definition. Display names alone are never identity.

## Transaction and durable steps

The subscription intent and its existing contact-outbox command are recorded inside the
host transaction on the same Laravel database connection. Recording outside a transaction
or configuring subscription, outbox and identity storage on different connections fails
before persistence. Remote requests are never made inside that transaction. After-commit
queue dispatch is only a wake-up; bounded pollers recover committed pending rows.

The durable prerequisites are:

1. the existing contact command completes and an active mapping is present;
2. the static segment is verified and its association is persisted; and
3. membership is added and verified through a contact-segment read.

The intent stores step progress. A restarted worker does not recreate a completed contact
or replay its field update. Membership waits for both earlier prerequisites. Segment
membership does not execute a campaign, submit a form, send email, renew consent or alter
DNC state.

## Concurrency and failures

Subscription reservations include an owner, monotonically increasing generation and exact
expiry. Acquisition matches the complete observed reservation, readback is bound to the
new reservation, and every transition requires that unexpired identity. A stale worker
cannot overwrite or borrow a replacement reservation.

Segment provisioning additionally persists connection/key state. Safe resolution work may
be taken over after expiry, but a durable `create_in_flight` marker is written before the
request. Its expiry becomes key-level ambiguity rather than permission to create again.
Every event using that exact connection/key remains blocked across workers, restarts and
poller runs until verified operator recovery clears it. This prevents coordinated package
workers from dispatching a second create while the first can still complete; it does not
claim protection against unrelated external creators because Mautic supplies no conditional
unique-create contract.

Segment lookup is bounded and validates exact alias, publication and empty filters.
Multiple candidates, a conflicting stored association, dynamic or unpublished segments
are terminal typed conflicts; the package never picks the first result or edits an
existing segment. Successful create identity is persisted before membership.

Potentially applied contact or segment creates are never blindly replayed. A create response
ID is durably captured before association, with a conservative fallback evidence write if
the first write fails. A later response-validation, association or local-completion failure
retains that ID on the key-level blocker and intent where storage remains available. If
storage itself remains unavailable, the worker reports that limitation and leaves the
durable in-flight marker blocking recreation rather than claiming evidence was persisted.
Membership is read before mutation and read again after success. These phases have separate
durable failure semantics. A transient initial GET uses bounded durable scheduling and a
numeric server `Retry-After` when present; queue delivery and polling cannot run it early.
Permanent or exhausted initial reads are actionable at `segment_ready` and may be resumed
without attesting to an unsent request. A response-proved POST rejection has the same safe
recovery property. In contrast, POST 5xx, malformed/unexpected responses, transport/timeout
outcomes and failed post-POST verification remain membership-in-flight ambiguity.

A membership mutation with uncertain outcome is not automatically replayed; an operator
must reconcile it. Read-only exact-membership confirmation may complete it, while replay
requires attestation that the prior request cannot still complete. Recovery reloads the
stored identity and writes an audit
containing actor, bounded reason, prior state and outcome. Contact recovery verifies the
completed outbox and active mapping. Membership uncertainty can be completed by observing
the exact remote membership; replay requires attestation that the earlier request cannot
complete. Segment-create recovery verifies the exact remote segment and requires equivalent
attestation before clearing the connection/key blocker.

No database transaction spans an HTTP request. The overall flow is resumable and locally
idempotent, but it is neither remotely atomic nor exactly once.

The shared segment key is mandatory for independent events that represent the same business
target. The 30-delivery acceptance covers an existing owned static segment and a missing
initial target with explicit create-if-absent, using multiple processes and recovery polling.
It is not campaign execution or a throughput guarantee. Create-if-absent never means that an
already associated segment deleted later may be recreated automatically; that case remains
blocked for operator repair.
