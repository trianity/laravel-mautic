# Mautic 7.2.1 OAuth restriction-write acceptance

Status: **accepted for explicit manual email block and channel-level release**

This report records installed-source, offline and live evidence for the stock REST API.
It does not claim independently owned restrictions, conditional release, renewed consent,
remote exactly-once processing or durable host synchronization.

## Inspected versions and source

- Package revision before this task: `1a5e00cbb03c2c7a478d76f9127dca2987b7baa7`.
- Installed adapter: `mautic/api-library` 4.0.0 at
  `3c8e071e2107e1c88203809dcb800ce093f638a9`.
- Installed server: `mautic/core-lib` 7.2.1 at
  `4a0fbebb76ac1bc84fb507227f21e103f7436d42`.

The SDK's `Contacts::addDnc()` sends reason, `channelId`, and comments to the contact/
channel add endpoint; its signature defaults `channelId` to null and always includes it
in the request (`lib/Api/Contacts.php:385-407`). `removeDnc()` sends an empty POST to the
contact/channel remove endpoint and has no reason, record-ID or version parameter
(`lib/Api/Contacts.php:409-421`).

Mautic casts a null channel ID to zero and keeps the channel unassociated with an
individual email entity. The add controller defaults to manual reason 3, rejects reason
zero, sanitizes comments through `InputHelper::clean()`, calls the DNC model and returns
the contact (`LeadApiController.php:418-453`). The remove controller passes only contact
and channel and returns `recordFound` plus the contact (`461-483`).

The model creates a row when the channel is contactable, updates an existing different
reason, refuses to override unsubscribe by default, and does nothing for the same reason
(`Model/DoNotContact.php:77-136`). Its remover iterates matching channel rows and returns
after removing the first selected row (`32-60`). These facts are why the package reports
resulting state without claiming ownership or all-row removal.

## Offline package evidence

The public API constructs a `ManualEmailBlock` from a required human explanation and a
package `Clock`. It captures one UTC timestamp and appends
`[trianity/laravel-mautic; recorded_at=…]`; reusing the object keeps the exact submitted
operation data. The writer always uses manual reason 3 and email channel with null
`channelId`. Release uses only the email-channel endpoint.

Real-SDK tests with controlled HTTP responses cover exact methods, endpoints and payloads;
stable repeated command data; invalid pre-network inputs; manual, retained unsubscribe,
unknown reason and empty resulting states; missing, null and malformed DNC payloads;
Missing `dateAdded`; release, no-match and restriction-remains outcomes; normalized
authentication, permission and validation failures; and ambiguous transport without
automatic replay. They also prove one GET fallback after an unusable embedded mutation
contact, with no replay of the write. A still-unverifiable post-mutation state throws
`RestrictionStateNotVerified` and explicitly indicates that the mutation may have
completed.

## Live OAuth procedure and result

The separately selected command is:

```shell
composer test:oauth-restriction-write
```

It requires `MAUTIC_OAUTH_RESTRICTION_WRITE_TESTS=1`, the existing fixture mutation
opt-in and the exact disposable-target acknowledgement. All gates are checked before
OAuth construction. The suite creates a cryptographically named contact for each
scenario and never selects the persistent manual-restriction reference fixture.

The authorized Mautic 7.2.1 run passed **1 test / 49 assertions**. The server's mutation
response embedded a contact shape that could not establish normalized DNC state, so each
public mutation used its one bounded GET fallback. No mutation was automatically
repeated. Observed results were:

| Isolated scenario | Observed result | Operation requests | Cleanup requests |
| --- | --- | ---: | ---: |
| Initially clean | Manual present; repeated same block remained manual; release succeeded; repeated release reported no match | 14 | 3 |
| Existing manual | Manual remained and original metadata was retained after a different explanation | 7 | 3 |
| Existing bounce | Reason 2 changed to manual; explicit channel release left no email restriction | 10 | 3 |
| Existing unsubscribe | Manual request retained reason 1; explicit channel release then removed it | 10 | 3 |

Mautic encoded the two submitted line feeds in block comments as `&#10;`. Unicode and
the diagnostic marker otherwise remained observable, but the typed result correctly
reported that submitted comments were not byte-exact. The fixture with an already manual
reason retained its original comments instead of replacing them. Optional `dateAdded`
continues to remain Missing when the API omits it.

Each independent budget allowed 16 operation attempts and reserved three cleanup
attempts. Every cleanup re-read the exact current contact ID and generated email, deleted
only that owned contact, then confirmed `ContactNotFound`. All four report `deleted`;
there are no unresolved or ambiguous creates. The mode-0600 ignored evidence contains
only outcome names, representation labels, request counts and cleanup status—no fixture
IDs, addresses, raw comments, credentials, tokens or unrestricted payloads.

## Remaining boundaries

Channel-level release may affect a manual, bounce or unsubscribe restriction and does
not establish consent, subscription or permission to send. The bounce scenario does not
clear or characterize historical hard/soft bounce evidence. A preflight read cannot make
the next mutation atomic. Repeated calls do not prove remote exactly-once execution.
Independent host ownership, conditional per-record release, automatic model hooks,
durable command delivery and a generic multi-channel API remain outside this task.

Final local `composer check` passed strict Composer validation, Pint, Larastan level 8
and 277 tests / 1184 assertions with four explicitly opt-in MariaDB tests skipped.
`git diff --check` passed. Remote CI was not run for this uncommitted worktree.
