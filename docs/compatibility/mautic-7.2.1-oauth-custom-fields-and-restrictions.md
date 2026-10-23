# Mautic 7.2.1 custom fields and delivery restrictions

Status: **accepted for the configured dedicated custom field**

This report records installed-source evidence and sanitized package tests. It does not
claim that a database column or internal method is available through REST.

## Installed-source evidence

- Contact PATCH processing reads submitted form values and enables blank overwrite only
  through `overwriteWithBlank`: `app/bundles/LeadBundle/Controller/Api/
  CustomFieldsApiControllerTrait.php:175-209`. `LeadModel.php:653-701` iterates actual
  field aliases, changes only aliases present in input, and requires blank overwrite for
  empty values. Contact-field list/item metadata exposes alias, type and object plus
  item properties such as required and unique-identifier flags in
  `app/bundles/LeadBundle/Entity/LeadField.php:339-369`. The installed SDK maps the
  `contactFields` context to `fields/contact` in `Api/ContactFields.php:17-23`.
- `DoNotContact.php:17-32` defines contactable `0`, unsubscribed `1`, bounced `2`, and
  manual `3`; `DoNotContact.php:98-116` serializes ID, date added, reason, comments,
  channel and channel ID for API reads.
- `Model/DoNotContact.php:76-135` may update an existing channel restriction when a
  different reason is added; create/update populate date and comments at lines 169-202.
  Its internal remover can filter by reason (`24-61`), but the REST controller calls it
  without that filter. `LeadApiController.php:447-510` proves add accepts reason,
  channel ID and comments while remove accepts only contact and channel. The installed
  SDK mirrors that channel-only removal in `Api/Contacts.php:385-421`.
- The bounce processor obtains parser category, code and type and stores those details in
  email-stat open details (`EmailBundle/MonitoredEmail/Processor/Bounce.php:96-123`), but
  creates only a reason-2 DNC row with translated comments (`70-91`). The contact DNC
  serializer above exposes neither subtype nor history, and no contact/email REST
  controller in the installed API exposes that stored bounce history. Consequently the
  current adapter reports a bounced restriction with `SubtypeUnavailable`; it does not
  infer hard/soft bounce from the DNC row or comments.

Paths above are relative to the inspected Mautic 7.2.1 `docroot`; SDK paths are relative
to this package's installed `mautic/api-library` 4.0.0.

## Package evidence and capability boundary

| Capability | Status | Evidence |
| --- | --- | --- |
| Standard/custom fields by actual alias | Implemented, offline-tested | Real SDK response/PATCH tests preserve omitted aliases and set/clear/unchanged intent. |
| Explicit blank clear | Implemented, offline-tested | Adapter adds `overwriteWithBlank` only for explicit null/empty-string PATCH values. |
| Custom-field OAuth lifecycle | Live-verified on Mautic 7.2.1 | Metadata preflight and the owned create/read/update/preserve/clear/delete lifecycle passed. |
| Segment read/add/remove | Live-verified on Mautic 7.2.1 | Reuses the accepted Task 07F-A report and implementation. |
| DNC ID/channel/reason/date/channel ID/comments | Implemented, offline-tested | Typed optionals preserve missing, null and values; malformed payloads fail. |
| Known/unknown DNC reasons | Implemented, offline-tested | Known constants map to an enum; raw unknown values remain available. |
| Bounce restriction subtype/history | Unsupported by current API | DNC reason 2 yields explicit `SubtypeUnavailable`; unknown evidence yields `Unavailable`. |
| Stock manual email block/channel release | Implemented and separately live-verified | Typed outcomes expose actual channel-level semantics; see the restriction-write report. |
| Independently host-owned conditional release | Deferred | Decision 005 preserves the stronger possible design without making a plugin a prerequisite. |

## Live custom-field prerequisite

Create this shared definition manually; the suite never creates, deletes or reconfigures
field definitions:

1. In Mautic Settings, open **Custom Fields**, create a **Contact** field, and choose
   type **Text**.
2. Set a non-empty descriptive label and choose a dedicated lowercase alias such as
   `test_field`.
3. Publish it, leave **Required** and **Unique identifier** disabled, and save it.
4. Put its actual alias in ignored `tests/.env` as
   `MAUTIC_LIVE_OAUTH_CUSTOM_FIELD_ALIAS=<alias>`.
   For the configured `test_field` alias, the related email token is
   `{contactfield=test_field}`.
5. Load `tests/.env`, explicitly export `MAUTIC_OAUTH_FIXTURE_TESTS=1`, retain the exact
   disposable acknowledgement, and run `composer test:oauth-custom-field`.

The command validates the configured alias through list and item metadata before contact
mutation. It shares the existing PSR-18 budget for metadata, discovery, API and possible
OAuth refresh requests (16 operation attempts), then uses the independent five-request
cleanup reserve and exact ID/email ownership check.

On 2026-10-23, the normal focused command skipped because the mutation opt-in was absent,
and an explicit run without the alias failed before OAuth configuration or networking.
After configuring the dedicated `test_field` alias, the authorized suite passed 1 test /
17 assertions. It validated list and item metadata, created one owned disposable contact,
read and updated the custom value, preserved it through an unrelated standard-field PATCH,
explicitly cleared it, and retained the known DNC and segment membership sets. Cleanup
re-read exact ID/email ownership, deleted the contact and confirmed `ContactNotFound`.
Sanitized evidence records `status: deleted`, 14 operation request attempts and three
cleanup request attempts. No email was sent and no shared field definition was changed.

Local `composer check` passed strict Composer validation, Pint, Larastan level 8 and
232 tests / 1065 assertions with four explicitly opt-in MariaDB tests skipped. The final
focused custom-field/restriction selection passed 42 tests / 125 assertions, and
`git diff --check` passed. Remote CI was not run for this uncommitted worktree.
