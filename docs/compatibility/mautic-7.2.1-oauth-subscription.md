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
Two independent PHP processes sharing persistent SQLite prove that the package segment
key lease permits only one coordinated create attempt.

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

## Stock API limitations

The stock API has no conditional unique-create or remote idempotency key for segments.
The database lease coordinates package workers only and cannot fence an unrelated
external creator. Local transactions cannot include remote requests. An uncertain
create or membership therefore remains actionable and is not blindly replayed. Segment
membership alone does not prove campaign execution or email delivery, and DNC remains a
separate unchanged state.
