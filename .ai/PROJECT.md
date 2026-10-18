# Project brief

## Objective

Build a public MIT Laravel package for reliable, controlled, two-way integration
between host CRM Contact entities and Mautic Contacts (historically Leads), using
Mautic's REST API. Publish on Packagist after explicit release approval.

## Stack

PHP ^8.4 (prefer 8.5), Laravel ^12|^13 (prefer 13), Pest/Testbench,
Laravel Pint, Larastan level 8. SDK dependency: `mautic/api-library:^4.0`.
Package: `trianity/laravel-mautic`; namespace: `Trianity\LaravelMautic`.

## Context

The first host currently posts to Mautic forms from a Laravel multi-site training
application. New CRM projects need contact segment reads and two-way synchronization.
The host domain model is Contact, independent of User or guards. Multiple named
Mautic connections are required; a site is not automatically a separate Mautic instance.

The owner maintains `trianity/api-library` as a contingency fork. SWIS laravel-mautic
was researched as a reference; this package does not depend on or copy it.

## Accepted decisions

- Three layers: SDK, reusable Laravel infrastructure, host-specific CRM rules.
- Explicit mapping, no implicit model-save observers or remote-delete cascade.
- REST reads first, then writes, then durable synchronization and webhooks.
- Generic contact updates cannot change marketing consent or remove DNC.
- Contact/Lead database tables are never accessed directly.
- Failed Mautic calls must not block a host's otherwise valid signup/order save.
- Support stale/unavailable state explicitly; never turn errors into an empty segment list.
- No automatic release, tag, Packagist submission, production writes or upstream edits.
