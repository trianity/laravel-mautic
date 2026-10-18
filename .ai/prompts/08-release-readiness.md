# Prompt 08 — Public API and release readiness review

Read AGENTS.md, .ai/STATE.md, .ai/PROJECT.md and docs/ARCHITECTURE.md first.
Inspect the installed SDK and current tests. Implement only this milestone using
Pest TDD, explicit types and model-independent contracts. Preserve PHP 8.4+ and
Laravel 12/13. Run focused tests, then composer check; report checks and limitations.
Update README, CHANGELOG and .ai/STATE.md with actual implemented behavior.
Do not tag, publish, call production Mautic or change host applications/upstream repos.

Review the implemented package against the project objective and docs/RELEASING.md.
Prepare a release candidate without tagging, publishing or submitting to Packagist.

Acceptance criteria:
- Review API naming, compatibility promises, DI extension contracts, model independence,
  connection isolation, data ownership, ambiguous mutations and error semantics.
- Public README examples execute against the actual implemented API. Planned work
  remains visibly planned. Audit docs for unsupported reliability/compatibility claims.
- Composer validate --strict, dependency audit, Pint, Larastan and Pest pass;
  compatibility CI is green on the exact reviewed commit.
- Inspect git archive contents and clean host install. Confirm provider discovery,
  config publishing, optional migrations/routes and no dependency on dev tools at runtime.
- Verify licenses for distributed code/dependencies and the SDK fork policy.
- Prepare changelog, proposed SemVer version, release notes, migration notes and
  known limitations. Confirm real-server evidence required by Task 07 exists.
- Give the owner a concrete readiness report and remaining blockers.

Stop before any release/tag/Packagist mutation; these are separate owner actions.
