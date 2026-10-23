# Mautic 7.2.1 OAuth static-segment acceptance

Status: **accepted for the configured dedicated static segment**

This report contains sanitized structural evidence only. It excludes credentials,
tokens, copied contact payloads and existing segment inventory.

## Environment and scope

- Package baseline revision: `87fba07` plus the uncommitted Task 07F worktree.
- PHP CLI 8.5.12; Laravel Framework 13.37.0; Testbench 11.3.0; Pest 5.3.3.
- Mautic API Library 4.0.0; installed Mautic core-lib 7.2.1.
- Intended origin: exact `https://mautic.test` through the existing package-managed
  OAuth2 connection and verified HTTPS transport.
- API family: the existing `/api` adapter. No production segment-definition API was
  added; fixture preflight uses the narrow raw SDK escape hatch.

## Verified safeguards

- `composer test:oauth-segment` selects only the static membership probe.
- The fixture mutation opt-in and exact disposable acknowledgement remain mandatory.
- `MAUTIC_LIVE_OAUTH_STATIC_SEGMENT_ID` is mandatory and is validated before OAuth
  configuration, contact creation or any remote mutation.
- The installed Mautic 7.2.1 API represents a segment definition under `list`; its
  `filters` array is the authoritative dynamic-filter evidence. The preflight requires
  the configured ID, exact name `lm-live-mautic721-static`, and `filters: []`. Missing,
  inaccessible, malformed, differently named and filtered segments are rejected.
- `isGlobal` is deliberately not used to infer static behavior.
- The prepared probe has a fixed 16-request operation budget plus bounded ownership
  cleanup. It records a possible membership before sending add, compares membership as
  ID sets, preserves the original failure, removes only the test-added membership and
  deletes only after exact contact ID/email ownership verification.
- Without its opt-in the focused suite skipped with zero assertions and no network
  access. With mutation gates enabled but no segment ID, it failed with the explicit
  missing-prerequisite error before constructing the OAuth configuration.
- `composer check` passed: Composer metadata and formatting checks were clean, Larastan
  level 8 reported no errors, and the default suite passed 212 tests / 1005 assertions
  with 4 explicitly opt-in MariaDB tests skipped.
- The authorized focused live rerun passed 1 test / 11 assertions. Its full operation
  path used 15 of the fixed 16-request budget before bounded cleanup.

## Live operation status

| Operation | Status | Evidence |
| --- | --- | --- |
| Configured segment exists and is accessible | Passed | OAuth preflight read succeeded. |
| Exact dedicated name | Passed | Exact `lm-live-mautic721-static` matched. |
| Explicitly empty dynamic filters | Passed | API returned an explicitly empty `filters` array. |
| Owned contact absence/create | Passed | Exact-email absence was established before each run-owned creation. |
| Initial membership and DNC reads | Passed | Target was absent and DNC was known before mutation. |
| Add and repeated add | Passed | Both commands succeeded; the second add created no duplicate or unrelated change. |
| Remove and repeated remove | Passed | Both commands succeeded and retained the original membership ID set. |
| Membership convergence | Passed | Every immediate read returned the expected set; polling was not required. |
| DNC preservation | Passed | The known DNC value was identical before and after membership operations. |
| Membership/contact cleanup | Passed | Membership was already absent; exact ownership was re-read, each owned contact was deleted, and `ContactNotFound` was confirmed. |

The first live execution completed the server operations and cleanup but failed its local
test assertion because it expected the request counter to equal 16. The observed complete
path uses 15 requests; correcting that test-only accounting made the immediate rerun pass.
Both execution evidence records report `status: deleted` and
`membership_cleanup: already_absent`. No customer contact was selected or changed.

## Dedicated fixture setup

1. In local Mautic, open `https://mautic.test/s/segments/new`.
2. Create a published segment named exactly `lm-live-mautic721-static`.
3. Make it visible to the dedicated OAuth test principal and leave Preference Center
   availability disabled.
4. Leave the Filters collection empty; do not add a dynamic rule.
5. Add only its numeric ID to ignored `tests/.env` as
   `MAUTIC_LIVE_OAUTH_STATIC_SEGMENT_ID=<id>`.

The ignored local segment ID was configured for this acceptance run. Future runs still
require that the dedicated segment retain the exact name and an empty filter collection;
the preflight fails before contact creation otherwise.

Dynamic segments, populated-DNC preservation, consent, campaigns, segment-definition
mutation, outbox/inbox integration and full host compatibility remain outside scope.
