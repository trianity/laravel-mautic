# Contact identity storage decision

Status: accepted for Task 04 before storage implementation.

## Reference and tenancy boundary

The core uses an opaque local reference made of `scope` and `key`. The host chooses
both strings. `scope` must be a stable, globally meaningful namespace that includes
the tenant boundary and domain type when either matters, for example
`tenant:acme:crm-contact`; `key` is the host contact's stable identifier. PHP class
names are not required and should not be used when a stable morph alias exists.

The package does not infer tenancy from a guard, model, database connection or current
request. Moving a contact between tenant scopes is a new identity and requires an
explicit host migration.

## Optional Laravel schema

The publishable `mautic_contact_mappings` table contains:

| Column | Type | Purpose |
| --- | --- | --- |
| `id` | unsigned bigint | Internal row identity only |
| `connection` | string(191) | Named Mautic connection |
| `local_scope` | string(191) | Host-defined tenant/domain namespace |
| `local_key` | string(191) | Stable opaque host contact key |
| `remote_id` | unsigned bigint | Mautic contact ID |
| `remote_state` | string(16) | `active`, `missing`, `deleted` or `merged` |
| `merged_into_remote_id` | nullable unsigned bigint | Review/recovery target for `merged` only |
| `generation` | unsigned bigint | Optimistic concurrency version, starting at 1 |
| timestamps | timestamps | Host diagnostics; not synchronization clocks |

The table has these database-enforced unique constraints:

1. `(connection, local_scope, local_key)` — one remote identity for one local contact
   on a named connection;
2. `(connection, remote_id)` — one local identity may claim a remote contact on a
   named connection.

The same local reference may map to different remote IDs on different connections.
The same numeric remote ID on different connections is unrelated.

## Transactions and concurrency

Initial claim is an insert guarded by both unique indexes. If concurrent claimers
race, the loser re-reads both unique keys. An exact existing pair is an idempotent
success; any crossed pair is an explicit conflict and is never silently merged.
The claim is a local database operation and does not pretend to transactionally cover
a preceding Mautic API request. Ambiguous remote creation must be reconciled before a
claim; Task 05 owns that durable workflow.

State transitions and relinking use compare-and-set updates on `generation` and the
expected identity. A stale caller fails explicitly. Relinking is the only recovery
operation that changes `remote_id`; missing, deleted or merged observations never
create or select another contact automatically.

Email is discovery input only and is not stored as identity. Zero, one and multiple
exact email matches remain distinct results. A unique result still requires an
explicit claim; ambiguous results remain reviewable. Changing an email does not alter
an established mapping.

## Supported database assumptions

The implementation relies on atomic unique-index enforcement and conditional
`UPDATE ... WHERE generation = ?` row counts. Claim does not require a multi-statement
transaction: the insert is the serialization point and subsequent reads only classify
its result. SQLite is covered by the default and concurrency tests. Current supported
Laravel versions require database versions capable of the declared composite indexes;
MariaDB/MySQL behavior must be verified in the compatibility environment before
claiming runtime parity. No advisory locks, database-specific upserts, deferrable
constraints or Mautic tables are used.

The migration is optional, explicitly publishable and never runs automatically.
Hosts may replace the repository contract with equivalent durable storage, but must
preserve both connection-scoped uniqueness rules and compare-and-set semantics.

## Lifecycle exclusions

There are no model saved/deleted observers, cascade deletion, remote recreation,
automatic merge, synchronization queue or default `Model::toArray()` mapping. Field
ownership, consent evidence, DNC and segment membership are separate concerns.
