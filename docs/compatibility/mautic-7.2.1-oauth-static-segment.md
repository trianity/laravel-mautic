# Mautic 7.2.1 OAuth static-segment acceptance

Status: **accepted for the configured dedicated static segment**

This report contains sanitized structural evidence only. It excludes credentials,
tokens, copied contact payloads and existing segment inventory.

## Environment and scope

- Package baseline revision: `395101e` plus the uncommitted Task 07F-A worktree.
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
- A test-only PSR-18 decorator wraps the existing verified local HTTP client factory.
  Every API and OAuth token `sendRequest()` attempt consumes the same 16-request
  operation budget before forwarding; failed transport attempts also count. A request
  beyond the limit is rejected before network activity without inspecting headers or
  bodies.
- Cleanup permanently switches the shared budget object to a separate 5-request
  allowance. It cannot reset operation accounting, and operation exhaustion cannot
  consume cleanup capacity. Cleanup errors remain explicit without replacing an earlier
  acceptance failure.
- The probe records a possible membership before sending add, compares membership as ID
  sets, removes only the test-added membership and deletes only after exact contact
  ID/email ownership verification.
- Without its opt-in the focused suite skipped with zero assertions and no network
  access. With mutation gates enabled but no segment ID, it failed with the explicit
  missing-prerequisite error before constructing the OAuth configuration.
- Four focused request-budget tests / 22 assertions prove limit enforcement before
  transport, shared accounting across factory-created clients, transport-failure
  accounting, independent cleanup capacity, secret-free errors and actual SDK
  create-preflight plus OAuth token endpoint wiring. The combined focused offline
  selection passed 21 tests / 58 assertions.
- `composer check` passed: Composer metadata and formatting checks were clean, Larastan
  level 8 reported no errors, and the default suite passed 216 tests / 1027 assertions
  with 4 explicitly opt-in MariaDB tests skipped.
- The authorized Task 07F-A live rerun passed 1 test / 12 assertions. The PSR-18 boundary
  measured 15 operation attempts and 3 separate cleanup attempts.

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
| Membership/contact cleanup | Passed | Membership was already absent; exact ownership was re-read, the owned contact was deleted, and `ContactNotFound` was confirmed. |
| Operation request budget | Passed | 15 actual PSR-18 attempts used from the fixed limit of 16. |
| Cleanup request budget | Passed | 3 actual PSR-18 attempts used from the independent allowance of 5. |

The earlier Task 07F implementation manually incremented an estimated operation counter.
Its successful functional results remain valid, but its reported 15 requests were not
transport-level evidence and did not account for possible OAuth refreshes or SDK-added
requests. Task 07F-A removed those estimates. The latest ignored evidence record reports
`operation_request_attempts: 15`, `cleanup_request_attempts: 3`, `status: deleted` and
`membership_cleanup: already_absent`, all measured at the actual PSR-18 boundary. No
customer contact was selected or changed.

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
