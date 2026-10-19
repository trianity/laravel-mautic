# Mautic 7.2.1 local OAuth acceptance

Status: **accepted for the exercised read-only connection scope**

This report contains sanitized evidence only. It intentionally excludes credentials,
tokens, callback query strings and contact payloads.

## Environment

- Package baseline commit: `1ed1d616c9a53aeedc1dcf49d2dec3e37cf9892a`.
- PHP CLI: 8.5.11.
- Laravel Framework 13.37.0, Testbench 11.3.0 and Pest 5.3.3.
- Mautic API Library 4.0.0, source revision
  `3c8e071e2107e1c88203809dcb800ce093f638a9`.
- Mautic: `mautic/core-lib` 7.2.1, Composer source revision
  `4a0fbebb76ac1bc84fb507227f21e103f7436d42`.
- API Platform: 4.4.3, Composer source revision
  `206a0ffc60afa7289eaf3ab73dcb1248897ff4d2`.
- Origin: exact `https://mautic.test`, resolving only to `127.0.0.1` during preflight.
- Primary API family: existing `/api` adapter.
- Separate probe: `GET /api/v2/contacts?page=1` from the captured OpenAPI contract.
- OAuth grant: Authorization Code followed by one controlled refresh-token grant.
- Dedicated callback: `http://127.0.0.1:8765/oauth/callback`.

## Completed preflight

- `tests/.env` is ignored and its values were not printed or committed.
- Package and installed Mautic non-development Composer platform requirements pass on
  PHP 8.5.11.
- The installed Mautic metadata reports exactly 7.2.1.
- Default system trust correctly rejected the local self-signed certificate. Certificate
  and hostname verification then succeeded using `/etc/ssl/certs/mautic.test.crt` as the
  explicit local trust anchor. Verification was not disabled.
- Redirect following remains disabled in the acceptance HTTP client.
- The installed routes are `/oauth/v2/authorize` and `/oauth/v2/token`.
- A dedicated OAuth2 client was subsequently provisioned with the exact callback above;
  its credentials remain only in ignored local configuration.

## Harness evidence

- The suite is selected only by `phpunit.oauth-live.xml` / `composer test:oauth-live` and
  requires the separate `MAUTIC_OAUTH_ACCEPTANCE_TESTS=1` opt-in. It does not load
  `tests/Live/LiveMauticTest.php`, even when `MAUTIC_LIVE_TESTS=1` exists.
- The exact-origin guard rejects HTTP, arbitrary `.test` names, subdomain lookalikes,
  missing DNS answers and any mixed non-loopback answer.
- OAuth client settings are mandatory; Basic Auth variables are not read as fallback.
- State is issued and consumed by the package's database state repository and
  `OAuth2Service`. The initiating context, encryption key and SQLite token database use
  ignored, mode-0600 local storage.
- The callback listener binds only `127.0.0.1:8765`, validates the exact Host and path,
  and does not log or print its query string.
- Focused offline guard/harness coverage passed with 8 tests / 19 assertions. Running the
  isolated suite without its dedicated opt-in produced 2 skips / 0 assertions and no
  network request.

## Executed probes

- Browser authorization and the Authorization Code exchange completed through the
  package service. The callback process persisted the encrypted token, and the isolated
  Pest process subsequently loaded it successfully.
- The bounded API v1 collection read returned one or fewer contacts as requested. Mautic
  7.2.1 exposed the collection `total` as a numeric string through the upstream SDK; the
  package now safely normalizes non-negative integer strings at the shared response
  boundary, and the public DTO retains an integer total.
- The isolated invalid Bearer request returned HTTP 401 with no redirect; it was not
  interpreted as an empty successful result and did not alter the valid stored token.
- One controlled expiry simulation and refresh completed in the dedicated repository.
  The persisted generation advanced from 1 to 3 (one expiry write plus one rotated-token
  write), the resulting expiry was in the future, and a post-refresh API v1 read passed.
- The separate Bearer-only `GET /api/v2/contacts?page=1` probe returned HTTP 200,
  `application/json`, with no redirect. This does not select API v2 as the package adapter.
- `composer test:oauth-live` passed 1 test / 19 assertions; 1 fixture-specific test was
  skipped.

## Skipped probes and remaining scope

Contact ID, exact-email, segment-membership and DNC reads were skipped because no dedicated
existing test contact ID/email was configured. No customer record was substituted. These
fixture-specific reads require a disposable dedicated fixture before they can be claimed.

No contact, segment, DNC, consent, outbox, inbox, reconciliation or host Form POST mutation
was executed. Mutation, outbox, inbox, reconciliation and full host acceptance remain
outside this report.
