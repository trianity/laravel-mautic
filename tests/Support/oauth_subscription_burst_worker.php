<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use Trianity\LaravelMautic\Contracts\ContactSyncTransport;
use Trianity\LaravelMautic\Contracts\StaticSegmentGateway;
use Trianity\LaravelMautic\Contracts\SubscriptionTransport;
use Trianity\LaravelMautic\Data\SegmentMemberships;
use Trianity\LaravelMautic\Identity\DatabaseContactMappingRepository;
use Trianity\LaravelMautic\MauticConnection;
use Trianity\LaravelMautic\OAuth\SystemClock;
use Trianity\LaravelMautic\Outbox\DatabaseOutboxStore;
use Trianity\LaravelMautic\Outbox\OutboxProcessor;
use Trianity\LaravelMautic\Segment\StaticSegment;
use Trianity\LaravelMautic\Segment\StaticSegmentDefinition;
use Trianity\LaravelMautic\Subscription\DatabaseSubscriptionStore;
use Trianity\LaravelMautic\Subscription\SubscriptionProcessor;
use Trianity\LaravelMautic\Subscription\SubscriptionStatus;
use Trianity\LaravelMautic\Tests\Support\OAuthAcceptanceHarness;
use Trianity\LaravelMautic\Tests\Support\OAuthAcceptanceRequestBudget;
use Trianity\LaravelMautic\Tests\Support\OAuthFixtureConfiguration;
use Trianity\LaravelMautic\Tests\Support\OAuthSubscriptionBurstControl;
use Trianity\LaravelMautic\Write\ContactFields;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$databasePath = $argv[1] ?? '';
$coordinationPath = $argv[2] ?? '';
$scenario = $argv[3] ?? '';
$worker = (int) ($argv[4] ?? -1);
$workers = (int) ($argv[5] ?? 0);
$lifetimeSeconds = (int) ($argv[6] ?? 0);
$workerName = 'worker-'.$worker;
$configuration = OAuthFixtureConfiguration::fromEnvironment();
$budget = OAuthAcceptanceRequestBudget::shared(
    $coordinationPath,
    $workerName,
    500,
    100,
    cleanupAllowed: false,
);
$connection = (new OAuthAcceptanceHarness($configuration->oauth, requestBudget: $budget))->connection();
$capsule = new Manager;
$capsule->addConnection(['driver' => 'sqlite', 'database' => $databasePath, 'prefix' => '']);
$capsule->setAsGlobal();
$capsule->getConnection()->statement('PRAGMA busy_timeout = 15000');
$control = new OAuthSubscriptionBurstControl($coordinationPath);
$contenderRecorded = false;
$events = static function (string $name) use ($control, $workerName): void {
    $control->event($name, $workerName);
};
$waitForContenders = static function () use ($control, $workers): void {
    $deadline = hrtime(true) + 15_000_000_000;
    while ($control->eventCount('contender_pending', distinctWorkers: true) < min(3, $workers - 1)) {
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Live subscription burst provisioning contention barrier timed out.');
        }
        usleep(10_000);
    }
};
$contactTransport = new class($connection) implements ContactSyncTransport
{
    public function __construct(private readonly MauticConnection $connection) {}

    public function create(string $connection, ContactFields $fields): int
    {
        return $this->connection->contactWrites()->create($fields)->remoteId;
    }

    public function update(string $connection, int $remoteId, ContactFields $fields): void
    {
        $this->connection->contactWrites()->update($remoteId, $fields);
    }
};
$gateway = new class($connection->staticSegments(), $scenario, $control, $events, $waitForContenders) implements StaticSegmentGateway
{
    /** @param Closure(string): void $events */
    public function __construct(
        private readonly StaticSegmentGateway $inner,
        private readonly string $scenario,
        private readonly OAuthSubscriptionBurstControl $control,
        private readonly Closure $events,
        private readonly Closure $waitForContenders,
    ) {}

    public function find(int $remoteId): StaticSegment
    {
        if ($this->scenario === 'existing'
            && $this->control->claimBarrier('resolver')) {
            ($this->events)('resolver_held');
            ($this->waitForContenders)();
        }

        return $this->inner->find($remoteId);
    }

    public function candidates(string $alias, int $limit = 10): array
    {
        return $this->inner->candidates($alias, $limit);
    }

    public function create(StaticSegmentDefinition $definition): StaticSegment
    {
        ($this->events)('segment_create_dispatch');
        ($this->waitForContenders)();

        return $this->inner->create($definition);
    }
};
$subscriptionTransport = new class($connection, $gateway) implements SubscriptionTransport
{
    public function __construct(
        private readonly MauticConnection $connection,
        private readonly StaticSegmentGateway $gateway,
    ) {}

    public function segments(string $connection): StaticSegmentGateway
    {
        return $this->gateway;
    }

    public function memberships(string $connection, int $contactId): SegmentMemberships
    {
        return $this->connection->contactReads()->segments($contactId);
    }

    public function addMembership(string $connection, int $contactId, int $segmentId): void
    {
        $this->connection->contactWrites()->addToSegment($contactId, $segmentId);
    }
};
$clock = new SystemClock;
$outbox = new DatabaseOutboxStore($capsule->getDatabaseManager(), $clock);
$store = new DatabaseSubscriptionStore($capsule->getDatabaseManager(), $clock);
$mappings = new DatabaseContactMappingRepository($capsule->getDatabaseManager());
$outboxProcessor = new OutboxProcessor($outbox, $contactTransport, $mappings, $clock);
$subscriptionProcessor = new SubscriptionProcessor($store, $outbox, $mappings, $subscriptionTransport);
$deadline = hrtime(true) + ($lifetimeSeconds * 1_000_000_000);
$control->workerReady($workerName);

try {
    while (true) {
        if ($control->stopIsRequested()) {
            break;
        }
        foreach ($store->recoverable(100) as $operationId) {
            $row = $capsule->table('mautic_subscription_intents')
                ->where('operation_id', $operationId)->first(['id', 'contact_operation_id']);
            if ($row === null || (((int) $row->id - 1) % $workers) !== $worker) {
                continue;
            }
            $outboxProcessor->process((string) $row->contact_operation_id);
            $subscriptionProcessor->process($operationId);
            if (! $contenderRecorded && $store->get($operationId)?->status === SubscriptionStatus::Pending) {
                $events('contender_pending');
                $contenderRecorded = true;
            }
        }
        $completed = (int) $capsule->table('mautic_subscription_intents')
            ->where('status', SubscriptionStatus::Completed->value)->count();
        if ($control->productionIsComplete() && $completed === 30) {
            break;
        }
        if (hrtime(true) >= $deadline) {
            $events('worker_deadline');
            throw new RuntimeException('Live subscription burst worker deadline exhausted.');
        }
        usleep(25_000);
    }
} catch (Throwable $exception) {
    $events('worker_failed');
    throw $exception;
} finally {
    $control->workerDone($workerName);
}
