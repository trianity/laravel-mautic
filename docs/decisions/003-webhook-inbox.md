# Decision 003: verified webhook inbox

## Status

Accepted for Task 06.

## Authenticated ingress boundary

The package targets Mautic 7.2's documented webhook contract: an event-type object whose
values are event arrays, and a `Webhook-Signature` header containing base64 HMAC-SHA256
over the exact raw request body. The algorithm and header name are fixed by the package,
not selected by request input. Comparison uses `hash_equals`. Body size is bounded before
hashing; signature verification happens before JSON parsing, database writes or dispatch.

Secrets are connection scoped and resolved at runtime from a configured environment
variable name through a replaceable contract. The package exposes a service but no HTTP
route. The host owns routing, error-to-status mapping, rate limiting and a narrowly scoped
CSRF exception. A successful response is valid only after the complete delivery commits.

## Durable storage and recovery

One delivery row records receipt metadata and body hash. One event row per batch item
stores its payload encrypted by the host encrypter, optional remote contact ID, optional
upstream event ID, optional timestamp and processing state. The raw body is not duplicated
or logged. Delivery and events commit in one transaction before queue dispatch.

Queue dispatch is a wake-up, not durability. A poller redelivers pending events and
processing rows whose leases expired. An event lease prevents duplicate ownership, and a
database lease over the connection/remote-contact identity serializes stale checks and
host projection across workers. A crash after host application but before inbox completion
may execute the event again; field-level change detection makes the repeated projection a
no-op. Exactly-once processing is not claimed.

## Identity, ordering and loop prevention

Mautic does not document a delivery UUID and does not guarantee that every event has an
event UUID or timestamp. Each authenticated request therefore gets a new receipt; body
hash is evidence, not a unique key. Byte-identical legitimate deliveries remain stored.
If an item explicitly contains `eventId` or `event_id`, a byte-exact tuple hash of that
value and event type is separate semantic identity and a later completed copy is marked
duplicate. Events without it are not invented an identity from mutable payload data.

For a mapped contact, a timestamp strictly older than an already completed event is stale.
Equal and missing timestamps are processed, then resolved by current-value comparison.
The mapping lookup and lease include the byte-exact connection boundary, preventing one
Mautic connection from changing another tenant's mapping.

The host supplies current allowlisted fields and a `FieldMapper` through
`InboundContactProjector`. Only `inboundValues()` are applied. Host-owned fields and
unchanged values produce no host write and no package outbox echo. Generic processing
supports contact update and mapping-state delete only. Consent, DNC and channel
subscription events are retained as ignored because they require separate evidence and
host workflows.

## Replay and retention limits

The Mautic signature covers no request timestamp, so authenticity is proven but freshness
is not. Anyone holding a previously signed body can replay it. Mapping, stale and change
checks limit side effects, but every authenticated replay is retained and acknowledged as
a new delivery. The package does not automatically delete inbox history. Hosts must set
and operate an explicit retention policy consistent with their audit and privacy duties.
