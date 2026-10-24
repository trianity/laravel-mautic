# Ordered implementation roadmap

| Task | Outcome | Dependency |
| --- | --- | --- |
| 00 | Local baseline and actual dependency/CI verification | Foundation |
| 01 | Typed contact and segment reads; normalized errors and fake | 00 |
| 02 | Explicit partial writes and segment commands, safe failure semantics | 01 |
| 03 | OAuth2 with durable encrypted token storage and serialized refresh | 01 |
| 04 | Model-independent identity mapping and host field ownership contracts | 02 |
| 05 | Durable outgoing synchronization with ambiguous-result recovery | 04 |
| 06 | Verified webhook inbox with idempotent processing and loop prevention | 04, 05 |
| 07 | Reconciliation, diagnostics, opt-in real Mautic tests and migration guide | 03, 05, 06 |
| 08 | Public API review and release readiness | 07 |
| 09 | Focused 1.0.0 release cleanup from the readiness audit | 08 |

The owner may reorder OAuth2 relative to writes. Existing form POST writes remain
host-owned until their effects are inventoried. Task 00 rechecks the PHP 8.4/8.5 and
Laravel 12/13 dependency matrix rather than assuming the preferred stack proves all.
Each prompt is a bounded milestone; a completed task must update STATE and CHANGELOG.
