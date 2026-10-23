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
use Trianity\LaravelMautic\Write\ContactFields;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$databasePath = $argv[1] ?? '';
$scenario = $argv[2] ?? '';
$worker = (int) ($argv[3] ?? -1);
$mode = $argv[4] ?? '';
$workers = (int) ($argv[5] ?? 0);
$configuration = OAuthFixtureConfiguration::fromEnvironment();
$budget = new OAuthAcceptanceRequestBudget(operationLimit: 100, cleanupLimit: 0);
$connection = (new OAuthAcceptanceHarness($configuration->oauth, requestBudget: $budget))->connection();
$capsule = new Manager;
$capsule->addConnection(['driver' => 'sqlite', 'database' => $databasePath, 'prefix' => '']);
$capsule->setAsGlobal();
$capsule->getConnection()->statement('PRAGMA busy_timeout = 15000');
$events = static function (string $name) use ($capsule, $worker): void {
    $capsule->table('subscription_live_burst_events')->insert(['name' => $name, 'worker' => (string) $worker]);
};
$waitForContenders = static function () use ($capsule, $workers): void {
    $deadline = microtime(true) + 20;
    while ($capsule->table('subscription_live_burst_events')->where('name', 'contender_pending')->count()
        < min(3, $workers - 1) && microtime(true) < $deadline) {
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
$gateway = new class($connection->staticSegments(), $scenario, $capsule, $events, $waitForContenders) implements StaticSegmentGateway
{
    /** @param Closure(string): void $events */
    public function __construct(
        private readonly StaticSegmentGateway $inner,
        private readonly string $scenario,
        private readonly Manager $database,
        private readonly Closure $events,
        private readonly Closure $waitForContenders,
    ) {}

    public function find(int $remoteId): StaticSegment
    {
        if ($this->scenario === 'existing'
            && $this->database->table('subscription_live_burst_barriers')->insertOrIgnore(['name' => 'resolver']) === 1) {
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

try {
    if ($mode === 'initial') {
        $events('worker_ready');
        $deadline = microtime(true) + 20;
        while ($capsule->table('subscription_live_burst_events')->where('name', 'worker_ready')->count() < $workers
            && microtime(true) < $deadline) {
            usleep(10_000);
        }
    }
    $rows = $capsule->table('mautic_subscription_intents')->orderBy('id')->get()->all();
    foreach ($rows as $index => $row) {
        if ($index % $workers !== $worker) {
            continue;
        }
        if ($mode === 'initial') {
            $outboxProcessor->process((string) $row->contact_operation_id);
        }
        $subscriptionProcessor->process((string) $row->operation_id);
        if ($mode === 'initial' && $store->get((string) $row->operation_id)?->status === SubscriptionStatus::Pending) {
            $events('contender_pending');
        }
    }
    $events('worker_done');
} finally {
    $capsule->table('subscription_live_burst_worker_requests')->insert([
        'worker' => (string) $worker.'-'.$mode,
        'attempts' => $budget->operationAttempts(),
    ]);
}
