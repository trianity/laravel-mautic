# Mautic 7.2.1 OAuth manual-restriction read acceptance

Status: **accepted with unavailable `dateAdded` metadata preserved**

This report covers only a read-only observation of an independently prepared manual
email restriction. It does not establish contactability, consent, bounce history or
support for administrative restriction writes.

## Inspected versions and source

- Package revision before this task: `104c13925f3978574f69846e35a5c6eaac2c9df7`.
- Installed package adapter: `mautic/api-library` 4.0.0 at
  `3c8e071e2107e1c88203809dcb800ce093f638a9`.
- Installed server source: `mautic/core-lib` 7.2.1 at
  `4a0fbebb76ac1bc84fb507227f21e103f7436d42`.
- Installed serializer bundle: `jms/serializer-bundle` 5.5.2 at
  `34d01be85521e99ca29079438002672af35ff9b0`. Its configured default date format is
  RFC3339, so the acceptance verifier requires a non-empty, exactly parseable RFC3339
  value without inventing an expected timestamp.

The installed Mautic DNC entity's API metadata lists `dateAdded`, but both observed
`GET /api/contacts/{id}` responses for this Mautic 7.2.1 fixture omitted it from the
selected restriction object. The public package mapper correctly represented that
optional property as Missing. This is a scoped observation about the tested version,
endpoint and fixture, not a claim that every Mautic response omits the property.
No timestamp was fabricated and the Mautic database was not queried.

## Acceptance procedure and result

The separately selected command is:

```shell
composer test:oauth-restriction-read
```

It requires `MAUTIC_OAUTH_RESTRICTION_READ_TESTS=1` plus the contact ID, exact email,
DNC record ID and exact comments in ignored `tests/.env`. Configuration is validated
before OAuth construction or networking. The committed tests and this report contain
only synthetic or redacted fixture data.

The initial live run incorrectly required `dateAdded` to be present. It failed after
three transport attempts: two contact reads and one OAuth token refresh. Its mode-0600
`restriction-read.json` evidence remains preserved as an expectation mismatch.

After correcting the optional-property expectation, the suite passed 1 test / 6
assertions. It made two contact reads through `contactReads()->find()`, observed no token
refresh and sent no mutation request. The four-request operation ceiling and zero-request
cleanup allowance were not exceeded. The corrected result was written separately to the
mode-0600 `restriction-read-corrected.json` evidence file.

The corrected verifier established across both typed responses:

- the configured contact ID and exact email;
- `doNotContact` Value presence with exactly one entry matching the configured typed
  remote DNC ID, without assuming that it was the first or only restriction;
- channel `email`, raw manual reason in the supported `3` representation and
  `knownReason()` equal to `DoNotContactReason::Manual`;
- explicit Null presence for `channelId`, distinct from Missing;
- exact configured comments, including Unicode and whitespace;
- Missing presence for `dateAdded` in both reads; and
- `BounceEvidence::NotBounced`, meaning only that this selected manual entry is not a
  reason-2 bounce restriction.

The repeated-state comparison includes `dateAdded` presence and compares its value only
when present. It also compares the DNC ID, channel, raw reason, channel-ID state and
comments. This proves two consistent observations of the supplied reference state; it
does not prove isolation from concurrent external changes.

The suite has no contact create, update, merge or delete path; no DNC, field, tag,
segment, subscription, consent or mail mutation; and no disposable-fixture cleanup.
No fixture identifiers, email, comments, tokens, credentials or raw payloads were
written to either evidence file.

## Offline verification

Focused tests cover opt-in/configuration gates before OAuth construction, exact record
selection among multiple restrictions, duplicate/missing IDs, explicit null versus
missing channel ID, exact Unicode comments, Missing/Null/valid/invalid dates, changed
date presence or value between responses, zero cleanup allowance and sanitized evidence.
Date-format validation applies only to a supplied Value. The default suite remains
offline and never selects this acceptance test. The final focused selection passed 27
tests / 69 assertions. `composer check` passed strict Composer validation, Pint and
Larastan level 8; the default Pest suite passed 255 tests / 1112 assertions with four
explicitly opt-in MariaDB tests skipped.

This task does not alter public runtime behavior, so no changelog entry was added.
