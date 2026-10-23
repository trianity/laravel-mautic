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

The historical Task 07J-B disposable Mautic 7.2.1 run passed both scenarios with 2 tests / 128 assertions
in 152.35 seconds. Each scenario scheduled 30 arrivals over 60 seconds, used four workers,
then processed the accumulated backlog after the last arrival. It completed all 30 intents
and verified all 30 exact memberships. The existing-segment case
dispatched no segment create; the missing-segment case dispatched exactly one. Neither run
left failed, blocked or ambiguous work, and the selected replays created no additional
contact, segment or intent. Each scenario used 217 operation attempts and 93 cleanup
attempts. Cleanup verified each generated contact identity and the generated segment,
deleted all 30 contacts and the segment, and confirmed every API not-found result. The
known non-empty manual DNC metadata remained unchanged.

## Corrected continuous-arrival live run

Task 07J-C starts all four workers and waits for their explicit ready barrier before the
first arrival. Workers continuously discover durable eligible work, remain alive while the
queue is temporarily empty, and stop only after producer completion and all 30 deliveries.
Harness coordination and the authoritative request counter use a small SQLite file separate
from delivery storage. No coordination transaction spans a wait or HTTP request. All precise
durations below are observations from one parent monotonic clock; worker clocks are not
combined.

The final disposable Mautic 7.2.1 run passed both scenarios with 2 tests / 94 assertions in
138.62 seconds. The existing-segment scenario recorded a 60.017-second arrival window,
60.561-second first-arrival-to-completion duration and 0.544-second post-arrival drain. Its
shared operation total was 216: parent 37 and workers 47/48/42/42. The missing-segment
scenario recorded 60.013 seconds, 60.583 seconds and 0.570 seconds respectively, using 217
operation attempts: parent 37 and workers 48/48/42/42. Both used 93 cleanup attempts.

At least one delivery completed before each final arrival. All 30 intents, mappings and
exact memberships completed in each scenario with one stable association, zero versus one
segment-create dispatch, genuine provisioning contenders, no failed/blocked/ambiguous
remainder and unchanged non-empty manual DNC metadata. Cleanup began only after all workers
were reaped, verified every owned identity, deleted all 30 contacts and the segment, and
confirmed every API not-found result.

An intermediate correction run exposed delivery/coordination SQLite writer contention.
The two contacts and segment owned by that failed run were subsequently identity-checked,
deleted and confirmed absent using nine cleanup attempts; its ignored diagnostic database
was retained. Separating coordination storage removed that contention in the final run.

The unchanged separately opted-in MariaDB multi-process burst regression also passed both
scenarios with 2 tests / 77 assertions. It remains storage/concurrency evidence, not live
Mautic transport evidence.
