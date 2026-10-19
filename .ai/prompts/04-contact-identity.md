# Prompt 04 — Contact mappings and field ownership

Read AGENTS.md, .ai/STATE.md, .ai/PROJECT.md and docs/ARCHITECTURE.md first.
Inspect the installed SDK and current tests. Implement only this milestone using
Pest TDD, explicit types and model-independent contracts. Preserve PHP 8.4+ and
Laravel 12/13. Run focused tests, then composer check; report checks and limitations.
Update README, CHANGELOG and .ai/STATE.md with actual implemented behavior.
Do not tag, publish, call production Mautic or change host applications/upstream repos.

Define a model-independent local Contact reference and connection-scoped remote-ID
mapping. Offer optional Laravel persistence without binding the core to User, a
guard, a particular Contact class or direct Mautic database tables.

Acceptance criteria:
- Unique mapping constraints and transaction/concurrency behavior are explicit.
- A contact may map to different remote IDs on different named connections.
- Initial exact email discovery handles zero/one/multiple matches; ambiguous matches
  are reviewable and never silently merged. Email change preserves established identity.
- Remote missing/deleted/merged state has explicit recovery, not automatic recreation.
- Host field mapper is an allowlist; field ownership resolves inbound/outbound/shared
  values and distinguishes absent, explicit clear and unchanged data.
- DNC, consent evidence and marketing segment state remain separate.
- Tests include a host-style Contact fixture with no User/guard dependency, shared
  email ambiguity, concurrent mapping attempts, and connection isolation.
- No automatic saved/deleted observers, cascade deletion or default Model::toArray().

Before coding optional storage, write a short design decision with schema,
uniqueness, tenancy and supported database assumptions. Keep this task focused on
identity and ownership; durable delivery follows in Task 05.

===

## Task 04A — Correct identity comparison and email normalization

This is a corrective continuation of Task 04. Preserve completed functionality.
Do not start Task 05.

Read AGENTS.md, .ai/STATE.md, docs/ARCHITECTURE.md and
docs/decisions/001-contact-identity-storage.md.
Inspect the current implementation and verify the findings below.
Use Pest TDD: reproduce each issue before implementing its correction.

### 1. Make opaque identity comparisons consistent across databases

The mapping migration currently lets connection, local_scope and local_key inherit
the database collation. On a case-insensitive MariaDB/MySQL database, distinct
opaque references can compare equal even though the PHP API accepts them separately.

Define and document byte-exact identity semantics:
- Case differences are significant.
- Accented and unaccented characters are distinct.
- Leading/trailing whitespace is significant for otherwise valid identifiers.
- Do not silently trim, lowercase or Unicode-normalize existing identity values.
- Preserve existing rejection of blank identifiers and the documented length limits.

Implement these semantics consistently for:
- local-reference and reverse remote-ID lookups;
- both database-enforced uniqueness constraints;
- idempotent claims and crossed-claim conflicts;
- generation-based state transitions and relinking;
- connection and tenant-scope isolation.

Choose a storage/index strategy that enforces these rules on SQLite and
MariaDB/MySQL. Do not assume that selecting a "_bin" collation alone guarantees
byte-exact behavior, particularly for trailing spaces. Verify the selected strategy
against the target database behavior.

Before changing storage, update the architecture decision with:
- the chosen representation and comparison rules;
- index sizes and supported database assumptions;
- how stored identity values round-trip unchanged;
- how existing development installations upgrade safely.

If hashes are used for indexed lookup, retain the original values and verify exact
identity before accepting a match. Never silently return a different reference.

Do not modify an already-run migration as the sole upgrade mechanism. Provide an
explicit upgrade path for existing tables. Detect incompatible existing data and
stop with an actionable error rather than silently merging or discarding mappings.

Tests must cover:
- identifiers differing only in case;
- accented versus unaccented identifiers;
- leading/trailing whitespace;
- distinct connection names and tenant scopes;
- exact-repeat claim idempotency;
- conflicts on both uniqueness directions;
- stale-generation rejection and relink conflicts;
- unchanged identity round-tripping.

Run these checks against the actual published migrations, not only a separately
handwritten test schema. Retain the real-process SQLite concurrency test.

Add an opt-in or CI service-backed MariaDB integration suite using a disposable
test database with a case-insensitive default collation. Never use a production
database or the production-data copy for migration/concurrency fixtures.
If MariaDB cannot be run, report that limitation explicitly; SQLite passing is
not evidence of MariaDB compatibility.

### 2. Normalize email consistently before querying

SdkContactDiscovery::exactEmail() normalizes email for local comparison but passes
the original input into the structured equality filter. Leading whitespace can
therefore produce a false "none" result before local comparison is reached.

Inspect the same behavior in SdkContactWriter's duplicate-email preflight and
centralize the shared normalization policy where appropriate.

Requirements:
- Apply the documented trim and ASCII case-folding policy consistently to discovery,
  duplicate comparison and intra-batch duplicate detection.
- Build the server query from input consistent with that policy.
- Verify the server-side equality behavior; do not assume an exact query against
  every possible server collation implements normalized matching.
- Preserve structured query parameters; do not interpolate email into search syntax.
- Preserve special characters such as +, %, _ and apostrophes.
- Preserve pagination and explicit zero/one/multiple discovery outcomes.
- Count distinct remote contact IDs when determining discovery ambiguity.
- Do not create mappings automatically or silently select an ambiguous match.
- Preserve the documented concurrent-create race limitation.

Add real-SDK/fake-HTTP regression tests showing:
- padded input produces the expected normalized structured query value;
- padded and mixed-case input can identify an existing exact normalized match;
- a similar but different address is not an exact match;
- multiple distinct exact matches remain ambiguous;
- duplicate-email preflight blocks the POST for an existing normalized match;
- pagination and special characters remain correct.

Assert outgoing request parameters as well as returned results. Do not use fake
responses that conceal an incorrectly constructed query.

### Completion criteria

- Existing mapping, recovery, ownership, OAuth2 and contact read/write tests remain green.
- No observers, automatic remote deletion/recreation or whole-model serialization.
- PHP 8.4+ and Laravel 12/13 compatibility is preserved.
- Update README, CHANGELOG, architecture decision and .ai/STATE.md with actual
  behavior, upgrade instructions and verification evidence.
- Run composer check and verify the remote compatibility CI after an authorized push.
- Do not lower Larastan's level, weaken tests or suppress failures.
- Do not modify upstream repositories, tag releases, publish to Packagist or
  implement Task 05.
- Report the corrections and distinguish SQLite, MariaDB and live-Mautic evidence.