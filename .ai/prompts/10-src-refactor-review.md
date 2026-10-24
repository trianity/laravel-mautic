# Task 10 — Behavior-preserving src refactor review before 1.0.0

Review the production source of trianity/laravel-mautic.

Baseline:
99d74bb95713b9db08c9605be588d15bf603e1a9

Read AGENTS.md, .ai/STATE.md, ARCHITECTURE, the architecture decisions, and:
docs/audits/1.0.0-readiness.md

Purpose

The feature work and release cleanup are complete.
The earlier readiness audit found no justified removal of runtime classes or
canonical migrations.

Now review the REFACTOR stage of TDD: improve the internal structure of working
code while preserving its established behavior.

This pass produces a ranked, evidence-backed refactor plan.
Do not implement proposed production refactors during this review.

Scope

Inspect all production PHP files under src/, tracing relevant tests, configuration,
bindings and documentation as needed.

Evaluate:
- Readability and cohesive responsibilities.
- Repeated logic and meaningful duplication.
- Long methods and deeply nested control flow.
- Naming consistency.
- Dependency direction and construction.
- Validation and response normalization.
- Exception handling and preservation of failure context.
- Type precision and nullable/optional-state handling.
- State-transition clarity.
- Service-provider registration structure.

Do not limit the review to unused-symbol searches.
Do not manufacture findings to achieve a target count or code-size reduction.

Release boundaries

Do not add features or revisit accepted product decisions.

Out of scope:
- Mautic plugins.
- Campaign configuration or execution.
- Email delivery.
- Real host business integration.
- New persistence models.
- Generic workflow or policy engines.
- New dependencies or dependency upgrades.
- Further release-archive cleanup unless a concrete regression is discovered.

1. Establish the behavioral baseline

Identify the contracts that a future refactor must preserve:

- Public namespaces, classes, interfaces, signatures and constructor contracts.
- Return DTOs, enum values and exception types.
- Configuration keys and defaults.
- Database schema, stored payloads, hashes and identity rules.
- Laravel discovery, bindings, lazy initialization and extension points.
- HTTP request methods, targets, payloads, order and number.
- Local transaction boundaries and after-commit behavior.
- Retry classification, timing, limits and Retry-After handling.
- Lease owner/generation/expiry conditions and conditional updates.
- Ambiguous-outcome handling and operator attestations.
- Audit behavior and sanitized diagnostics.
- Missing, Null and Value distinctions.
- Public fakes used by consuming applications.

Inspect the actual implementation and callers rather than assuming every public
method is merely internal.

Do not propose breaking these contracts as cleanup.

2. Review each subsystem

Cover:

- Manager, connection and service provider.
- Configuration and connection/authentication factories.
- SDK response boundaries, metadata and contact/segment mapping.
- Contact reads, writes, discovery and restriction commands.
- Contact identity and field ownership.
- OAuth authorization, tokens, refresh and persistence.
- Contact outbox.
- Webhook inbox and projection.
- Reconciliation, diagnostics and mapping recovery.
- Subscription capture, provisioning, membership and operator recovery.
- Console commands, jobs, DTOs, enums and public fakes.

For each subsystem, record either concrete candidates or a brief explanation that
no worthwhile pre-release refactor was identified.

Prioritize maintainability issues demonstrated by actual code, not generic style
preferences.

3. Assess duplication carefully

For similar implementations, compare their behavior before recommending extraction.

Particular caution is required for:
- Outbox, inbox, reconciliation and subscription claims.
- Remote create versus update versus membership mutation.
- Preflight reads versus post-mutation verification.
- Retryable rejection versus uncertain completion.
- Different identity hashes and persisted representations.

Similar syntax does not establish identical semantics.

Recommend a shared helper only when:
- Its responsibility can be named precisely.
- The existing behaviors are demonstrably equivalent.
- It reduces reasoning effort.
- It does not require mode flags, callbacks or configurable policy machinery
  merely to accommodate unrelated cases.

Intentional duplication may be preferable to coupling independent state machines.

4. Identify concrete readability improvements

Consider whether small private methods, clearer local names or simpler control
flow can improve understanding.

Examples to investigate, not predetermined changes:
- Separating pure response parsing from remote orchestration.
- Making phase-specific error handling explicit.
- Extracting repeated validation with identical contracts.
- Naming repeated state predicates.
- Organizing service registrations by coherent subsystem.
- Removing redundant intermediate values or unnecessary branches.

Do not:
- Split methods solely to meet an arbitrary line limit.
- Replace explicit safety checks with clever compact expressions.
- Reorder validation, exceptions, SQL or HTTP side effects.
- Introduce abstractions solely to reduce line count.
- Remove defensive checks because a happy-path test does not need them.
- Catch broader exceptions or discard original failure evidence.
- Change integer/string normalization or hash canonicalization inadvertently.

5. Classify each candidate

For every proposed refactor provide:

- Priority.
- Exact files, symbols and relevant lines.
- The concrete maintenance problem.
- The smallest proposed transformation.
- Expected benefit.
- Public and persisted contracts affected, if any.
- Behavioral invariants that must remain unchanged.
- Regression risk: low, medium or high, with a reason.
- Existing tests that protect the behavior.
- Any justified missing characterization coverage.
- Whether it should be done before 1.0.0 or deferred.

Separate:
A. Small, worthwhile pre-release refactors.
B. Valid improvements better deferred because of risk or scope.
C. Code that looks repetitive or complex but should remain as designed.

Distinguish a correctness defect from a refactor candidate.
If a defect is discovered, report its trigger and consequence separately rather
than silently fixing it within a structural refactor.

6. Review test adequacy for the proposed changes

Existing tests are the primary behavioral safety net.

Identify gaps only where a selected refactor could change meaningful observable
behavior without current tests noticing.

Useful characterization checks may include:
- Exact HTTP sequence and payload.
- No request before validation.
- One fallback read without mutation replay.
- Retained remote evidence after persistence failure.
- Stale claim rejection.
- Transaction rollback and lost-dispatch recovery.
- Correct operator recovery eligibility.
- Unknown/missing/null response preservation.

Do not propose tests that simply mirror private method structure.
Do not rewrite successful concurrency tests into in-memory substitutes.
Do not use assertion-count changes as evidence of improved coverage.

7. Produce an implementation plan

Create:
docs/audits/1.0.0-src-refactor.md

Include:
- Audited commit and review scope.
- Subsystem findings.
- Ranked candidate table.
- Intentional complexity to preserve.
- Separately identified correctness defects, if any.
- A small recommended pre-release selection.
- An ordered implementation and verification plan.

Prefer a few cohesive, reviewable changes over a large sweeping refactor.
If no candidate offers a clear benefit at acceptable risk, state that conclusion.

For each recommended implementation step, specify:
- The bounded code change.
- Tests to run immediately afterward.
- Conditions that would justify broader checks.
- How a reviewer can confirm behavior was preserved.

A future implementation should complete one coherent refactor at a time, keep
tests green, and avoid combining structural work with new behavior.

8. Verification during this review

Run the existing offline baseline checks where practical:
- composer check
- git diff --check

Use static inspection and focused read-only analysis as needed.
Do not install permanent analysis tools or change dependency constraints.

Do not enable live Mautic or MariaDB suites, source tests/.env, mutate remote
fixtures, or run a host database reset.

Record exact checks performed and limitations.
Do not claim that source review proves concurrency behavior independently of
the existing process-level tests.

Completion

Leave only the review report and, if necessary, a concise .ai/STATE.md handoff
update as intended changes.

Do not modify production code, migrations, configuration, tests or public APIs
during this pass.

Do not alter the completed release-cleanup decisions or move local .idea material.

The final response must identify:
- Recommended pre-release refactors.
- Changes intentionally deferred.
- Safety-critical complexity retained.
- Any separately discovered correctness issue.
- The proposed first implementation step.

Do not commit, push, tag or publish.