# Mautic 7.2.1 durable subscription compatibility

## Scope

Task 07J targets the stock OAuth-backed `/api` endpoints in Mautic 7.2.1. The package
uses segment `GET`, bounded list search and `POST /segments/new`, then the existing
manual contact-membership endpoint and contact segment read. It does not require a
plugin or direct Mautic database access.

## Offline evidence

Controlled HTTP tests through the real upstream SDK verify segment response mapping,
exact-alias filtering, create payloads, unsuitable create rejection and the complete
existing/create-if-absent membership path. Testbench storage tests verify atomic local
capture, replay conflict behavior, lost-dispatch recovery and connection/key isolation.
Two independent PHP processes sharing persistent SQLite prove a short-duration coordinated
resolution. Genuine multi-process processor tests hold a create behind a deterministic
barrier, expire its reservation, and also terminate a worker immediately after the durable
marker. In both cases a second event sends no create; the delayed-worker case additionally
proves the key-level blocker retains the eventual remote ID. Offline
fault injection separately covers the first evidence write and association write failing.

The adapter requires a positive ID, non-empty name and alias, boolean `isPublished` and
`isGlobal`, and a list-valued `filters` property. Subscription targets must be published
and have no filters. HTTP success from membership mutation is not treated as proof;
membership must appear in the subsequent contact-segment read.

## Live acceptance gate

Run only against the disposable loopback Mautic installation:

```bash
set -a
source tests/.env
set +a
MAUTIC_OAUTH_FIXTURE_TESTS=1 MAUTIC_OAUTH_SUBSCRIPTION_TESTS=1 composer test:oauth-subscription
```

The suite also requires the established OAuth fixture mutation opt-in, exact disposable
acknowledgement and prepared static segment ID. It uses cryptographically named owned
fixtures, the durable public API and processors, finite operation/cleanup budgets, exact
ownership checks and API not-found cleanup confirmation. It never uses the persistent
Task 07H contact. A run is not compatibility evidence unless its result and request
counts are recorded here.

## Verified run — 2026-10-23

The final separately gated run passed 1 test / 14 assertions. Its operation phase used
15 HTTP/OAuth attempts from a limit of 28; cleanup used six attempts from a limit of 10.
No token refresh was needed, while both allowances retain room for one if required.

The durable processors created one cryptographically named contact, placed it in the
prepared owned static segment, accepted an exact replay without another flow, processed
a second accepted event through the existing mapping, created a cryptographically named
missing static segment and verified membership. The final contact read retained the
initial DNC state. Cleanup re-read exact contact email and exact segment name/alias,
deleted only those two run-owned resources, and confirmed both API not-found contracts.
Sanitized mode-0600 evidence contains only the run hash, completion/cleanup outcomes and
request counts; it contains no remote ID or email.

This historical Task 07J run started with an empty DNC set. It is not evidence for the
stronger Task 07J-A requirement to preserve a known non-empty manual restriction; that
requires a new separately recorded live run and is not retroactively claimed here.

## Task 07J-A verified correction run — 2026-10-23

Offline coverage now verifies generation-bound claims, replacement between claim update and
readback, stale transition rejection, cross-event key blocking, create-ID fallback evidence,
association failure and audited verified association. The canonical schema includes durable
provisioning state and subscription recovery audits. The separately opt-in MariaDB test is a
real schema, transaction, uniqueness and conditional-transition check; it is not a MariaDB
multi-process race. The genuine multi-process concurrency evidence uses shared SQLite.

The strengthened OAuth suite passed 1 test / 16 assertions. It used 18 operation attempts
from a limit of 34 and six cleanup attempts from a limit of 10. A public contact-outbox
create first established the exact mapping for a cryptographically named disposable contact.
The public restriction writer then installed a non-empty manual email restriction before
either accepted subscription event. Both subscription events completed, including contact
update, prepared-segment membership and create-if-absent membership. The final typed contact
read retained the exact restriction ID, manual reason, email channel, channel-ID presence,
comments and other optional metadata presence/value. No restriction release was called.

Ownership-checked cleanup deleted the run-owned contact and segment and confirmed the API
not-found contract. Sanitized mode-0600 evidence records completed/deleted outcomes and the
18/6 request counts without contact IDs, addresses or restriction comments. This remains
Testbench plus disposable local Mautic evidence; no real host application was exercised.

## Stock API limitations

The stock API has no conditional unique-create or remote idempotency key for segments.
The database reservation coordinates package workers only and cannot fence an unrelated
external creator or cancel an already dispatched HTTP request. Local transactions cannot
include remote requests. An uncertain create blocks its exact connection/key until verified,
audited recovery; uncertain membership is not replayed without observation or attestation. Segment
membership alone does not prove campaign execution or email delivery, and DNC remains a
separate unchanged state.
