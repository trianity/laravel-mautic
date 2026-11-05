# Upgrading Laravel Mautic

## 1.1 to 1.2

Version 1.2 keeps the 1.1 single-segment API, stored intent shape and
`SubscriptionTransport` contract. It adds `SubscriptionDelivery::recordPlan()`, the
`MembershipPlanTransport` extension and one forward subscription migration. Existing
single-segment intents are not rewritten and may remain pending across the upgrade.

The new plan API is not safe to enable until every application instance uses the 1.2 code,
the forward migration has completed and all queue workers have been restarted. A 1.1 worker
does not understand plan items, while 1.2 code expects the plan-item table when recording or
processing a membership plan.

### Supported order

1. Keep every `recordPlan()` call site behind a disabled deployment flag. Existing
   `record()` calls may continue until the maintenance boundary.
2. Stop dispatchers that can enqueue subscription or contact-outbox work, then stop or fully
   drain every 1.1 subscription, contact-outbox and recovery worker. Confirm that no old
   long-running worker remains.
3. Deploy the 1.2 package to every application instance. Do not restart workers yet.
4. Publish the subscription migrations without `--force`, so an existing published 1.1
   migration is not overwritten:

   ```bash
   php artisan vendor:publish --tag=mautic-subscription-migrations
   php artisan migrate:status
   php artisan migrate --force
   ```

5. Confirm that `2026_11_05_000000_add_mautic_subscription_membership_plans` ran and that
   `mautic_subscription_plan_items` exists on the database configured by
   `mautic.subscription.database_connection`. The earlier
   `2026_10_19_050000_create_mautic_subscription_delivery` migration must remain unchanged.
6. Restart the 1.2 subscription, contact-outbox and recovery workers. Re-enable normal
   dispatch and verify that pending legacy intents continue through their original ordering.
7. Enable `recordPlan()` only after the preceding checks pass on every instance.

Do not perform a rolling overlap between 1.1 workers and membership-plan writes. If workers
cannot be stopped together, keep plan writes disabled until the last 1.1 worker has exited,
the migration is complete and all replacements run 1.2. Restart long-running workers even
when the host uses a queue system that normally reloads code automatically.

### Custom transports

An existing implementation of `SubscriptionTransport` remains valid and continues to process
legacy single-segment work. Membership plans require removal as well as addition, so a host
that replaces the default transport must implement the additive `MembershipPlanTransport`
before enabling `recordPlan()`. Without it, plan processing stops with
`membership_plan_transport_unavailable`; it does not treat the plan as successful.

### Failure and rollback boundary

Membership plans are deterministic but not remotely atomic. The processor resolves all
targets, applies absent requirements before present requirements in canonical target-key
order, verifies each mutation and verifies the full plan. An earlier successful mutation is
not rolled back when a later target fails. Ambiguous writes require read-only observation or
an audited operator replay attestation; polling does not replay them automatically.

Before any plan has been recorded, rollback may restore the 1.1 package while leaving the
additive table in place. After plan writes are enabled, do not run the migration `down()` or
return workers to 1.1: stop plan dispatch, preserve the plan rows and resolve or complete the
recorded work with 1.2 code. Never use `migrate:fresh` for this upgrade.

The package does not change DNC, consent, campaign membership or mail delivery as a side
effect of subscription membership processing. Hosts must verify those business effects
separately.
