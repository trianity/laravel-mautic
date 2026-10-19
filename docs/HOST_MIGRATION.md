# Host migration guide

This package does not reproduce Mautic Form submit actions automatically. Migrate one
host behavior at a time and retain the existing form write until its effects have been
inventoried and deliberately replaced.

## 1. Inventory the current form path

For every host form, record:

- the exact Mautic Form and named connection;
- mapped fields, hidden attribution values and contact merge behavior;
- Form actions, notifications and redirects;
- campaign entry, tags, static and dynamic segment changes;
- consent evidence, DNC behavior and email sends;
- retry behavior and what the host currently does when Mautic is unavailable.

Treat campaign execution, email delivery, segment membership and consent as separate
effects. An API contact create or patch does not prove that a Form action ran.

## 2. Introduce reads first

Use normalized contact and segment reads while leaving the existing Form POST unchanged.
Keep failures and timeouts as unknown/stale state; never turn them into “no segments” or
“contact absent.” Add authorization in the host before exposing history or membership.

Create explicit local-to-Mautic mappings only after the host has resolved zero, unique or
ambiguous discovery. Email remains discovery data, not identity. Run diagnostics and a
dry reconciliation scan to establish the initial mismatch inventory.

## 3. Prepare durable writes

Define field ownership and an allowlist. Exclude consent, DNC, tags and segment state
from generic field mapping. Publish the identity, outbox and reconciliation migrations
without resetting the host database. Configure queue workers and bounded outbox recovery,
then test ambiguous create/update operator procedures.

Do not run the Form POST and REST/outbox write for the same business event. That can
duplicate contacts, campaign entry, notifications or email. Select one low-risk action,
disable only its old write after verifying every inventoried effect, and preserve an
explicit rollback path.

## 4. Add inbound processing deliberately

Expose the host-owned webhook route only after configuring its exact CSRF exception,
secret, allowed events and queue recovery. Bind an inbound projector with current host
values and field ownership. Consent and DNC require their own evidence-driven workflow.
Do not infer remote deletion from a failed or incomplete scan.

## 5. Prove and monitor

Run the offline suite on every change. Use the opt-in live suite only against a confirmed
loopback, disposable Mautic installation and dedicated fixture prefix. Record the exact
PHP, Laravel, SDK and Mautic versions and which optional probes actually ran.

Schedule bounded `mautic:reconcile` invocations and inspect `mautic:diagnose`. Run
`mautic:reconcile-mappings` without `--apply-missing` first. Apply a confirmed missing
mapping only with an operator identity and reason code. Review pending/ambiguous outbox
work, failed inbox work and OAuth health before and after each migration stage.

Set the reconciliation request reservation longer than every Mautic request timeout and
run schedulers frequently enough to resume expired reservations. Treat a blocked OAuth
scan as an operator condition, not as an ordinary retry. Honor server-directed retry
delays, and never infer absence from a failed, contradictory or incomplete page.

Because the package is unreleased, disposable installations that ran earlier identity or
reconciliation stubs must recreate those package test schemas before using the canonical
migrations. Do not automatically reset a host database and never run `migrate:fresh`
against a host application or production database copy.
