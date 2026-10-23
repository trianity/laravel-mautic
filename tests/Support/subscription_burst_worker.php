<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Contracts\ContactSyncTransport;
use Trianity\LaravelMautic\Contracts\StaticSegmentGateway;
use Trianity\LaravelMautic\Contracts\SubscriptionTransport;
use Trianity\LaravelMautic\Data\SegmentMembership;
use Trianity\LaravelMautic\Data\SegmentMemberships;
use Trianity\LaravelMautic\Identity\DatabaseContactMappingRepository;
use Trianity\LaravelMautic\Outbox\DatabaseOutboxStore;
use Trianity\LaravelMautic\Outbox\OutboxProcessor;
use Trianity\LaravelMautic\Segment\StaticSegment;
use Trianity\LaravelMautic\Segment\StaticSegmentDefinition;
use Trianity\LaravelMautic\Subscription\DatabaseSubscriptionStore;
use Trianity\LaravelMautic\Subscription\SubscriptionProcessor;
use Trianity\LaravelMautic\Subscription\SubscriptionStatus;
use Trianity\LaravelMautic\Write\ContactFields;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$driver = $argv[1] ?? '';
$databasePath = $argv[2] ?? '';
$scenario = $argv[3] ?? '';
$worker = (int) ($argv[4] ?? -1);
$mode = $argv[5] ?? '';
$workers = (int) ($argv[6] ?? 0);

$capsule = new Manager;
if ($driver === 'sqlite') {
    $capsule->addConnection(['driver' => 'sqlite', 'database' => $databasePath, 'prefix' => '']);
} else {
    $capsule->addConnection([
        'driver' => 'mysql',
        'host' => getenv('MAUTIC_TEST_MARIADB_HOST'),
        'port' => (int) (getenv('MAUTIC_TEST_MARIADB_PORT') ?: 3306),
        'database' => getenv('MAUTIC_TEST_MARIADB_DATABASE'),
        'username' => getenv('MAUTIC_TEST_MARIADB_USERNAME') ?: 'root',
        'password' => getenv('MAUTIC_TEST_MARIADB_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
    ]);
}
$capsule->setAsGlobal();
if ($driver === 'sqlite') {
    $capsule->getConnection()->statement('PRAGMA busy_timeout = 15000');
}

$clock = new class($capsule) implements Clock
{
    public function __construct(private readonly Manager $database) {}

    public function now(): int
    {
        return (int) $this->database->table('subscription_burst_control')
            ->where('name', 'clock')->value('value');
    }
};
$events = static function (string $name) use ($capsule, $worker): void {
    $capsule->table('subscription_burst_events')->insert(['name' => $name, 'worker' => (string) $worker]);
};

$contactTransport = new class($capsule, $events) implements ContactSyncTransport
{
    /** @param Closure(string): void $events */
    public function __construct(private readonly Manager $database, private readonly Closure $events) {}

    public function create(string $connection, ContactFields $fields): int
    {
        $email = $fields->value('email');
        if (! is_string($email)) {
            throw new RuntimeException('The burst contact email is missing.');
        }
        $id = (int) $this->database->table('subscription_burst_contacts')->insertGetId([
            'email' => $email,
            'dnc' => str_contains($email, 'contact-00@') ? 'manual-owned-restriction' : '',
        ]);
        ($this->events)('contact_create');

        return $id;
    }

    public function update(string $connection, int $remoteId, ContactFields $fields): void
    {
        ($this->events)('unexpected_contact_update');
    }
};

$gateway = new class($capsule, $scenario, $events, $workers) implements StaticSegmentGateway
{
    /** @param Closure(string): void $events */
    public function __construct(
        private readonly Manager $database,
        private readonly string $scenario,
        private readonly Closure $events,
        private readonly int $workers,
    ) {}

    public function find(int $remoteId): StaticSegment
    {
        $row = $this->database->table('subscription_burst_remote_segments')->where('id', $remoteId)->first();
        if ($row === null) {
            throw new RuntimeException('Burst segment is missing.');
        }

        return $this->segment($row);
    }

    public function candidates(string $alias, int $limit = 10): array
    {
        if ($this->database->table('subscription_burst_barriers')->insertOrIgnore([
            'name' => $this->scenario.'-resolver',
        ]) === 1 && $this->scenario === 'existing') {
            ($this->events)('resolver_held');
            $this->waitForContenders();
        }
        $rows = $this->database->table('subscription_burst_remote_segments')
            ->where('alias', $alias)->limit($limit)->get()->all();

        return array_map(fn (object $row): StaticSegment => $this->segment($row), $rows);
    }

    public function create(StaticSegmentDefinition $definition): StaticSegment
    {
        ($this->events)('segment_create_dispatch');
        $this->waitForContenders();
        $id = (int) $this->database->table('subscription_burst_remote_segments')->insertGetId([
            'name' => $definition->name,
            'alias' => $definition->alias,
            'is_global' => $definition->global,
        ]);

        return new StaticSegment($id, $definition->name, $definition->alias, true, $definition->global, []);
    }

    private function waitForContenders(): void
    {
        $deadline = microtime(true) + 15;
        while ($this->database->table('subscription_burst_events')->where('name', 'contender_pending')->count()
            < min(3, $this->workers - 1) && microtime(true) < $deadline) {
            usleep(10_000);
        }
    }

    private function segment(object $row): StaticSegment
    {
        return new StaticSegment((int) $row->id, (string) $row->name, (string) $row->alias, true, (bool) $row->is_global, []);
    }
};

$subscriptionTransport = new class($capsule, $gateway, $events) implements SubscriptionTransport
{
    /** @param Closure(string): void $events */
    public function __construct(
        private readonly Manager $database,
        private readonly StaticSegmentGateway $gateway,
        private readonly Closure $events,
    ) {}

    public function segments(string $connection): StaticSegmentGateway
    {
        return $this->gateway;
    }

    public function memberships(string $connection, int $contactId): SegmentMemberships
    {
        $ids = $this->database->table('subscription_burst_memberships')
            ->where('contact_id', $contactId)->pluck('segment_id')->all();

        return new SegmentMemberships($contactId, array_map(
            static fn (mixed $id): SegmentMembership => new SegmentMembership((int) $id, 'Burst segment', 'burst-segment'),
            $ids,
        ));
    }

    public function addMembership(string $connection, int $contactId, int $segmentId): void
    {
        $this->database->table('subscription_burst_memberships')->insertOrIgnore([
            'contact_id' => $contactId,
            'segment_id' => $segmentId,
        ]);
        ($this->events)('membership_add');
    }
};

$store = new DatabaseSubscriptionStore($capsule->getDatabaseManager(), $clock);
$outbox = new DatabaseOutboxStore($capsule->getDatabaseManager(), $clock);
$mappings = new DatabaseContactMappingRepository($capsule->getDatabaseManager());
$outboxProcessor = new OutboxProcessor($outbox, $contactTransport, $mappings, $clock, 30, 3, 30);
$subscriptionProcessor = new SubscriptionProcessor($store, $outbox, $mappings, $subscriptionTransport, 30, 3, 30);

if ($mode === 'initial') {
    $events('worker_ready');
    $deadline = microtime(true) + 15;
    while ($capsule->table('subscription_burst_events')->where('name', 'worker_ready')->count() < $workers
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
    $record = $store->get((string) $row->operation_id);
    if ($mode === 'initial' && $record?->status === SubscriptionStatus::Pending) {
        $events('contender_pending');
    }
}
$events('worker_done');
