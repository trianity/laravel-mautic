# Subscription burst acceptance

Date: 2026-10-23

The deterministic acceptance creates 30 distinct accepted-subscription intents through the
public `SubscriptionDelivery` API. Four independent PHP processes run the real contact
outbox and subscription processors against shared storage. A controlled resolver barrier
forces contenders to observe provisioning in progress, and the public recovery poller
drives their later continuation.

Both SQLite scenarios passed: an existing static segment produced zero create dispatches,
and an initially missing create-if-absent target produced exactly one. The separately opted
in disposable MariaDB run passed the same two scenarios. Each scenario finished 30 contacts,
30 mappings, 30 memberships and 30 completed intents with one segment-key association, no
failed/blocked/ambiguous remainder, unchanged non-empty DNC fixture metadata, and no extra
work after selected event replays.

This evidence is a bounded correctness and modest-concurrency check. It does not establish
production latency, capacity, remote exactly-once execution, campaign entry or email
delivery.

The live harness is separately gated and uses cryptographically named contacts/segments,
four PHP workers, the shared encrypted OAuth token store, finite worker budgets with an
aggregate counter, a configurable 60--120 second arrival window, bounded queue drain,
typed membership/DNC verification and ownership-checked cleanup:

```bash
set -a
source tests/.env
set +a
MAUTIC_OAUTH_FIXTURE_TESTS=1 MAUTIC_OAUTH_SUBSCRIPTION_BURST_TESTS=1 composer test:oauth-subscription-burst
```

The disposable Mautic 7.2.1 live run passed both scenarios with 2 tests / 128 assertions
in 152.35 seconds. Each scenario scheduled 30 arrivals over 60 seconds, used four workers,
completed all 30 intents and verified all 30 exact memberships. The existing-segment case
dispatched no segment create; the missing-segment case dispatched exactly one. Neither run
left failed, blocked or ambiguous work, and the selected replays created no additional
contact, segment or intent. Each scenario used 217 operation attempts and 93 cleanup
attempts. Cleanup verified each generated contact identity and the generated segment,
deleted all 30 contacts and the segment, and confirmed every API not-found result. The
known non-empty manual DNC metadata remained unchanged.
