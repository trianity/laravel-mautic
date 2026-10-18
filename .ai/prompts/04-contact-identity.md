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
