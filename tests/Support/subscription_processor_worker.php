<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Contracts\StaticSegmentGateway;
use Trianity\LaravelMautic\Contracts\SubscriptionTransport;
use Trianity\LaravelMautic\Data\SegmentMemberships;
use Trianity\LaravelMautic\Identity\DatabaseContactMappingRepository;
use Trianity\LaravelMautic\Outbox\DatabaseOutboxStore;
use Trianity\LaravelMautic\Segment\StaticSegment;
use Trianity\LaravelMautic\Segment\StaticSegmentDefinition;
use Trianity\LaravelMautic\Subscription\DatabaseSubscriptionStore;
use Trianity\LaravelMautic\Subscription\SubscriptionProcessor;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$databasePath = $argv[1] ?? '';
$operationId = $argv[2] ?? '';
$role = $argv[3] ?? '';
$capsule = new Manager;
$capsule->addConnection(['driver' => 'sqlite', 'database' => $databasePath, 'prefix' => '']);
$capsule->setAsGlobal();
$capsule->getConnection()->statement('PRAGMA busy_timeout = 10000');
$clock = new class($capsule) implements Clock
{
    public function __construct(private readonly Manager $database) {}

    public function now(): int
    {
        return (int) $this->database->table('subscription_process_control')->where('name', 'clock')->value('value');
    }
};
$gateway = new class($capsule, $role) implements StaticSegmentGateway
{
    public function __construct(private readonly Manager $database, private readonly string $role) {}

    public function find(int $remoteId): StaticSegment
    {
        return new StaticSegment($remoteId, 'Concurrent segment', 'concurrent-segment', true, true, []);
    }

    public function candidates(string $alias, int $limit = 10): array
    {
        return [];
    }

    public function create(StaticSegmentDefinition $definition): StaticSegment
    {
        $this->database->table('subscription_process_events')->insert([
            'name' => 'create_dispatched', 'worker' => $this->role,
        ]);
        if ($this->role === 'lost') {
            exit(0);
        }
        if ($this->role === 'first') {
            $deadline = microtime(true) + 10;
            while (! $this->database->table('subscription_process_events')->where('name', 'release_create')->exists()
                && microtime(true) < $deadline) {
                usleep(10_000);
            }
        }

        return new StaticSegment(301, $definition->name, $definition->alias, true, $definition->global, []);
    }
};
$transport = new class($gateway) implements SubscriptionTransport
{
    public function __construct(private readonly StaticSegmentGateway $gateway) {}

    public function segments(string $connection): StaticSegmentGateway
    {
        return $this->gateway;
    }

    public function memberships(string $connection, int $contactId): SegmentMemberships
    {
        return new SegmentMemberships($contactId, []);
    }

    public function addMembership(string $connection, int $contactId, int $segmentId): void {}
};

if (str_starts_with($role, 'second')) {
    $deadline = microtime(true) + 10;
    while (! $capsule->table('subscription_process_events')->where('name', 'create_dispatched')->exists()
        && microtime(true) < $deadline) {
        usleep(10_000);
    }
    $capsule->table('subscription_process_control')->where('name', 'clock')->update(['value' => 106]);
}

$processor = new SubscriptionProcessor(
    new DatabaseSubscriptionStore($capsule->getDatabaseManager(), $clock),
    new DatabaseOutboxStore($capsule->getDatabaseManager(), $clock),
    new DatabaseContactMappingRepository($capsule->getDatabaseManager()),
    $transport,
    5,
);
try {
    $processor->process($operationId);
} catch (Throwable $exception) {
    $capsule->table('subscription_process_events')->insert([
        'name' => 'worker_exception', 'worker' => $role,
    ]);
}
if (str_starts_with($role, 'second')) {
    $capsule->table('subscription_process_events')->insert(['name' => 'release_create', 'worker' => $role]);
}
$capsule->table('subscription_process_events')->insert(['name' => 'worker_done', 'worker' => $role]);
