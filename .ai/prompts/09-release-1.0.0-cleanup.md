Task 09 — Focused cleanup for the first 1.0.0 release

Work in trianity/laravel-mautic.

Read AGENTS.md and:
docs/audits/1.0.0-readiness.md

Implement the focused cleanup identified by the audit and the decisions below.

Preserve existing runtime behavior and database schemas.
Do not add features or redesign the package.

Local directory convention

In this project, .idea/ is the user's intentionally untracked directory for ideas
and local reference material. It is not being used as IDE configuration storage.

Respect this convention.

1. Move the raw OpenAPI reference into untracked local storage

Move the large Mautic 7.2.1 API v2 OpenAPI snapshot from the tracked reference
directory into an appropriate subdirectory of .idea/.

Preserve alongside it the useful provenance information:
- Mautic and API Platform versions.
- Export command.
- Inspected source revision.
- Export date, if known.
- SHA-256 checksum.

Do not overwrite unrelated existing local material.
Verify the moved snapshot's checksum before removing the tracked copy.

Ensure .idea/ is ignored and excluded from the release archive.
Do not force-add its contents.

Keep concise, useful capability conclusions in tracked documentation where they
remain relevant.

Update tracked references so they do not link to a removed file or require an
untracked local path to understand the published package.

Document the supported regeneration procedure where useful. Make clear that the
local snapshot is an optional development reference, not a runtime dependency.

Do not regenerate it by booting or changing Mautic during this cleanup.

2. Keep active PHPUnit configurations in Git, exclude them from distribution

Retain PHPUnit configurations used by development commands or CI.

Exclude test configurations, test infrastructure and fixtures from the distributed
package using precise .gitattributes export-ignore rules.

Trace references before deleting anything.
Do not move active PHPUnit configurations into untracked storage.

3. Exclude AI development material from the release archive

Keep useful AI instructions and task history versioned in the repository.

Exclude .ai/ and applicable AI-only instruction/tooling files from the release
archive through .gitattributes.

Inspect the actual repository inventory rather than assuming filenames.

Do not exclude runtime resources, migration stubs or necessary installation
documentation by using overly broad patterns.

Ensure README and release-facing documentation do not contain broken relative
links to material excluded from the archive. Use repository URLs where appropriate.

4. Add a dedicated OAuth migration publication tag

Add:
mautic-oauth-migrations

It must publish only the canonical OAuth migration.

Preserve:
- The existing aggregate mautic-migrations tag.
- Existing subsystem-specific tags.
- The six canonical migrations and their schemas.

Do not add compatibility bridges or development upgrade migrations.
Do not merge unrelated optional subsystems merely to reduce file count.
Do not run migrations implicitly.

Add focused publication regression coverage proving:
- OAuth-only publication includes exactly its intended migration.
- Aggregate publication includes every canonical migration exactly once.
- Existing subsystem mappings remain correct.

5. Document migration dependencies clearly

Update README installation guidance with a concise table containing:
- Feature/subsystem.
- Publication tag.
- Required companion migrations.
- Tables created or their purpose.

Derive dependencies from the actual implementation.

Make the distinction clear between:
- Minimal OAuth setup.
- Optional identity/outbox/inbox/reconciliation/subscription functionality.
- Deliberately installing all package migrations.

Do not recommend the aggregate tag as a prerequisite for OAuth alone.

Preserve configuration guidance for database connections and custom table names.

6. Correct Composer requirements and release metadata

Add an explicit ext-json runtime requirement.

Review Composer metadata against the audit findings.
Do not perform an unrelated dependency update or switch SDK providers.

Do not hard-code a Composer package version simply to prepare 1.0.0;
the release version will come from the Git tag.

Preserve the audited PHP/Laravel constraints and intended dependency resolution.

7. Clean release-facing documentation

Update README, CHANGELOG and .ai/STATE.md consistently.

Remove or correct:
- Obsolete development-status statements.
- Contradictory capability descriptions.
- References to removed files.
- Relative links to files absent from the distributed archive.
- Instructions that depend on a local checkout but are presented as consumer setup.

Retain precise limitations:
- Compatibility evidence is version- and capability-specific.
- Host business integration has not yet been verified.
- Campaign configuration and execution are separate Mautic responsibilities.
- No independent restriction ownership or remote exactly-once guarantee is offered.

Do not turn those deferred integrations into new release blockers.

Keep useful historical acceptance evidence in Git.
Do not rewrite old test results as if they came from the cleanup.

Prepare a concise initial-release changelog entry according to repository
conventions, without claiming that publication has already happened.

8. Verify actual distribution contents

Build a local prospective release archive using the Git attributes that will
govern distribution.

Ensure the archive includes the current cleanup changes, not an outdated HEAD.
Use an appropriate temporary index or staging export method without altering
the user's actual staging area or creating a commit.

Inspect the archive and verify:

Included:
- Runtime source.
- Composer metadata.
- Required configuration.
- All publishable migration stubs.
- MIT license.
- README and necessary consumer documentation.

Excluded:
- .ai and AI-only development material.
- .idea.
- Tests, worker scripts and test fixtures.
- PHPUnit configurations.
- The raw OpenAPI snapshot.
- Local environments, credentials, databases, logs and generated evidence.
- Other development-only artifacts identified by the audit.

Do not rely solely on reading .gitattributes.
Check the resulting file inventory.

9. Verify installation from the archive

Use a disposable consumer environment outside the repository.

Install the package from the generated archive, not from a path repository pointing
at the full working tree.

Verify where practical:
- Dependency installation without development dependencies.
- Laravel package discovery and provider boot.
- Configuration publication.
- OAuth-only migration publication.
- Aggregate migration publication without duplicate files.
- Clean schema creation using a disposable database.
- Resolution of documented public services.

No live Mautic credentials, real host business integration or campaign configuration
are required.

Do not modify another application's source, reset a host database, or contact the
live Mautic installation.

Clearly distinguish checks actually run from checks that could not be performed.

10. Final verification and report

Run:
- Focused publication tests.
- composer validate --strict.
- composer check.
- git diff --check.
- The archive-content and archive-consumer checks above.

Review Git status:
- The OpenAPI tracked-file removal is intentional.
- Its local replacement exists and has the verified checksum.
- .idea contents remain untracked and ignored.
- No generated archive, temporary consumer, credentials or database is staged.

Update the audit report with the disposition and verification of its findings.
Preserve its original audited-commit context.

Report:
- Exact tracked changes.
- Local reference relocation and checksum verification.
- Distribution inclusions/exclusions.
- Migration publication results.
- Archive-based consumer installation results.
- Remaining release blockers, if any.

Make no speculative cleanup changes beyond the audit's supported findings.
Do not delete justified runtime, recovery or concurrency code.

Do not commit, push, tag, create a release or publish to Packagist.
Leave the cleanup ready for review.