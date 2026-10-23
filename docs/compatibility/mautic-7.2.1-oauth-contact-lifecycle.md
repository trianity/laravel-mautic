# Mautic 7.2.1 OAuth contact-lifecycle acceptance

Status: **accepted for the exercised dedicated contact scope**

This report contains sanitized structural evidence only. It excludes credentials,
tokens, callback queries and copied contact payloads.

## Environment

- Package baseline revision: `280d94a8e18ce0b73cfd4a89d6fd096969aa2b4f` plus the
  uncommitted Task 07E worktree documented here.
- PHP CLI 8.5.12; Laravel Framework 13.37.0; Testbench 11.3.0; Pest 5.3.3.
- Mautic API Library 4.0.0 at `3c8e071e2107e1c88203809dcb800ce093f638a9`.
- Mautic core-lib 7.2.1 at `4a0fbebb76ac1bc84fb507227f21e103f7436d42`;
  API Platform 4.4.3 at `206a0ffc60afa7289eaf3ab73dcb1248897ff4d2`.
- Exact origin `https://mautic.test`, exclusively loopback-resolved, with verified TLS,
  redirects disabled and the existing package-managed OAuth2 token repository/lock.
- API family: the existing `/api` adapter. API v2 was not used for mutations.

## Gates and ownership

- `phpunit.oauth-fixture.xml` and `composer test:oauth-fixture` select only this suite.
- Both `MAUTIC_OAUTH_FIXTURE_TESTS=1` and the exact
  `MAUTIC_LIVE_DISPOSABLE=I_UNDERSTAND_THIS_DELETES_OWN_FIXTURES` acknowledgement are
  mandatory in addition to the existing OAuth acceptance configuration.
- The read-only OAuth opt-in alone skips before configuration or networking.
- Each run uses `lm-live-mautic721.<cryptographic-run-id>@example.test` and writes an
  ignored mode-0600 evidence file containing only run ID, exact fixture email, created
  remote ID and cleanup status.
- Cleanup re-reads the exact remote ID and requires the exact recorded email before the
  raw SDK deletion escape hatch is used. Search results and prefix matches never
  authorize deletion. A typed post-delete lookup must report `ContactNotFound`.

## Executed lifecycle

The final authorized suite passed 1 test / 34 assertions.

| Operation | Result | Evidence boundary |
| --- | --- | --- |
| Exact-email absence preflight | Passed | Explicit `none`; existing or ambiguous results abort. |
| Create email/name/phone | Passed | Typed create returned the owned remote ID. |
| Typed read by ID | Passed | ID and all four expected fields matched. |
| Normalized discovery | Passed | Whitespace and ASCII case variation resolved only to the owned ID. |
| Firstname-only PATCH | Passed | Email, lastname and phone remained unchanged. |
| Explicit phone clear | Passed | Empty-string intent became an empty remote value; unrelated fields remained unchanged. |
| DNC preservation | Passed | Known empty DNC state remained known and equal across generic updates. This does not prove populated-DNC preservation. |
| Duplicate-create preflight | Passed | `ContactAlreadyExists`; discovery still returned only the owned ID. |
| Optional static segment | Skipped | No dedicated static test segment ID was configured. |
| Ownership-checked cleanup | Passed | Every contact created across diagnostic and final runs has evidence status `deleted`; final typed lookup returned `ContactNotFound`. |

## Compatibility correction

Mautic 7.2.1 deliberately ignores blank contact fields on POST/PATCH unless its
`overwriteWithBlank` request control is true. Both JSON null and an empty string first
produced successful no-op updates in the live probe. Offline regression coverage now
requires the `/api` writer to add this adapter-owned control only when an update
contains an explicit null or empty string, including batch items. `ContactFields`
rejects callers attempting to supply the control directly. The final live clear passed.

The read-only OAuth suite also passed 1 test / 19 assertions after its controlled
refresh baseline moved to immediately before expiry simulation. Offline coverage proves
an exact two-generation delta for both initially valid and initially expired tokens.
Local `composer check` passed strict Composer validation, Pint, Larastan level 8 and
205 tests / 995 assertions; four opt-in MariaDB tests were skipped. Both OAuth suites
also skipped before networking when invoked without their respective opt-ins. Remote CI
was not run.

## Remaining limitations

Static-segment membership is unverified because no dedicated segment was configured.
Dynamic segments, populated-DNC preservation, consent, campaigns, segment definitions,
outbox/inbox integration, reconciliation and full host acceptance remain pending. No
customer contact, DNC entry, consent state, campaign, form integration or database was
reset or bulk-modified.
