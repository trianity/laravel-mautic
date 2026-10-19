# API v2 capability inventory

This inventory maps the package's contact synchronization requirements to the installed
Mautic 7.2.1 API v2 OpenAPI snapshot. "Documented" means only that the exported contract
contains the stated operation or shape. Runtime semantics, authorization and compatibility
remain unverified unless explicitly stated otherwise.

## Contact operations

| Requirement | Status | Contract evidence | Established by the specification | Runtime verification still required |
| --- | --- | --- | --- | --- |
| Contact lookup by ID | Documented; requires runtime verification | `GET /api/v2/contacts/{id}`, `api_contacts_id_get`; response `Contacts-contact.read`; errors `Error` | An ID path parameter, a contact response, and 403/404 responses are described. | ID coercion, field completeness, permission behavior, not-found payload and response headers. |
| Exact email filtering/search | Not found in the exported contract | `GET /api/v2/contacts`, `api_contacts_get_collection`, documents only the `page` query parameter. | No email or generic search parameter is advertised for the contact collection. | Whether an undocumented filter exists must not be assumed; any candidate needs explicit live evidence before adoption. |
| Contact creation | Documented; requires runtime verification | `POST /api/v2/contacts`, `api_contacts_post`; request `Contacts-contact.write`; response `Contacts-contact.read`; errors 400/403/422. | A contact creation operation and its allowed documented fields are present. | Required/unique field rules, duplicate-email behavior, returned ID, authorization, validation payloads and ambiguous transport outcomes. |
| Partial contact update | Documented; requires runtime verification | `PATCH /api/v2/contacts/{id}`, `api_contacts_id_patch`; media type `application/merge-patch+json`; request `Contacts-contact.write.jsonMergePatch`. | PATCH is distinct from PUT, and the merge-patch schema has no required field list. | Whether omitted fields remain unchanged for every supported representation and whether SDK transport preserves the intended body. |
| Explicit field clearing | Documented shape; requires runtime verification | The merge-patch properties, including `email`, `firstname`, `lastname`, `phone` and address fields, allow `null`. | The contract accepts `null` for documented nullable scalar fields. | Actual clearing semantics, validation constraints, custom fields and fields that may normalize `null` differently. |

## Segments, consent and subscriptions

| Requirement | Status | Contract evidence | Established by the specification | Runtime verification still required |
| --- | --- | --- | --- | --- |
| Contact segment membership reads | Not found in the exported contract | No contact membership path or segment-membership property exists. `GET /api/v2/segments` (`api_segments_get_collection`) and `GET /api/v2/segments/{id}` (`api_segments_id_get`) read segment resources, not membership. | Segment CRUD must not be treated as contact-membership evidence. | A future candidate operation or representation needs separate contract and live verification. |
| Static segment membership changes | Not found in the exported contract | No add/remove membership operation is exported. Segment POST/PUT/PATCH operations modify the segment resource itself. | The snapshot exposes no documented equivalent of the package's manual membership commands. | Any undocumented or later API must be tested for static versus dynamic segment behavior, authorization and idempotency. |
| Contact DNC reads | Not found in the exported contract | No contact DNC path or property appears in `Contacts-contact.read`. | Email-resource properties named `sendToDnc` and an IP `doNotTrackList` property are unrelated and do not establish contact DNC support. | A dedicated contact DNC representation and its permission/error behavior would need runtime and contract evidence. |
| Subscription representations | Not found in the exported contract | No contact subscription or channel-subscription path/property is exported. `/api/v2/channels` is channel resource CRUD, not contact subscription state. | Similar resource names do not establish subscription parity. | Representation, consent boundaries and mutation semantics remain wholly undecided. |

## Reconciliation collection semantics

| Requirement | Status | Contract evidence | Established by the specification | Runtime verification still required |
| --- | --- | --- | --- | --- |
| ID filtering / fixed range | Not found in the exported contract | `GET /api/v2/contacts` exposes only `page`; no ID equality/range parameter. | The current package's fixed high-water range cannot be reproduced from the documented v2 parameters alone. | Do not adapt reconciliation until a stable, bounded query is proven. |
| Ordering / keyset pagination | Not found in the exported contract | No ordering, direction, cursor or last-ID parameter is documented. | Ascending ID order and keyset progress are not contract guarantees. | Stable order under concurrent changes and no-gap/no-duplicate traversal require live evidence. |
| Pagination | Documented; requires runtime verification | `GET /api/v2/contacts`, `api_contacts_get_collection`, query parameter `page` (integer, default 1); JSON-LD collection uses `HydraCollectionBaseSchema`. | Page-number pagination and Hydra first/last/previous/next links are described. | Default/fixed page size, terminal-page behavior, maximums, concurrent mutation behavior and non-JSON-LD shapes. |
| Totals | Documented for JSON-LD only; requires runtime verification | `HydraCollectionBaseSchemaNoPagination.totalItems` is a non-negative integer. The plain JSON response is an array. | JSON-LD collections have a documented total field; a total is not documented for every media type. | Accuracy and consistency of `totalItems` with filters/pages and behavior on permission-limited collections. |

The exported collection operation does not document a page-size control. Page-number
pagination plus a JSON-LD total is not sufficient evidence for the package's current
bounded, ascending-ID reconciliation invariants.

## Errors and security

| Requirement | Status | Contract evidence | Established by the specification | Runtime verification still required |
| --- | --- | --- | --- | --- |
| Error representations | Documented; requires runtime verification | Contact operations reference `Error` for 400/403/404 and `ConstraintViolation` for 422, with JSON, Problem JSON, JSON-LD and JSON:API variants as applicable. | Common problem fields such as `title`, `detail`, `status`, `type`, `instance`, `meta` and `source`, plus validation-error shapes, are described. | Actual media negotiation, payload stability, authentication failures, rate limiting, 5xx responses and transport headers such as `Retry-After`. |
| OAuth/Bearer security requirements | Not found in the exported contract | Top-level `security` is empty, `components.securitySchemes` is empty, and no operation has a `security` requirement. | The OpenAPI document does not tell a client how to authenticate and cannot prove OAuth/Bearer access. | Installed source config enables the Mautic OAuth firewall and HTTP Basic for `/api/v2`, but token issuance, scopes/permissions and operation access require explicit live tests. A logged-in browser rendering `/api/v2` is not OAuth evidence. |

## Adoption decision

API v2 adoption remains undecided. The snapshot supports investigating ID lookup, create
and merge-patch contact writes, but does not document several read, membership, consent
and reconciliation capabilities on which the package relies. No runtime adapter should
be selected from this inventory alone.

The canonical live-harness variable is `MAUTIC_LIVE_USERNAME`, consistently with the
package README and `tests/Live/LiveMauticTest.php`. The ignored local `tests/.env` has
been aligned by the operator; its value is not reproduced in this reference.
