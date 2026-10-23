<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Identity\DatabaseContactMappingRepository;
use Trianity\LaravelMautic\Identity\LocalContactReference;
use Trianity\LaravelMautic\Outbox\ContactOutbox;
use Trianity\LaravelMautic\Outbox\DatabaseOutboxStore;
use Trianity\LaravelMautic\Outbox\OutboxDispatcher;
use Trianity\LaravelMautic\Segment\StaticSegmentDefinition;
use Trianity\LaravelMautic\Subscription\DatabaseSubscriptionStore;
use Trianity\LaravelMautic\Subscription\SegmentTarget;
use Trianity\LaravelMautic\Subscription\SubscriptionCommand;
use Trianity\LaravelMautic\Subscription\SubscriptionDelivery;
use Trianity\LaravelMautic\Subscription\SubscriptionDispatcher;
use Trianity\LaravelMautic\Subscription\SubscriptionPoller;
use Trianity\LaravelMautic\Tests\TestCase;
use Trianity\LaravelMautic\Write\ContactFields;

uses(TestCase::class);

/** @return array{0: string, 1: string} */
function subscriptionBurstDatabase(string $driver): array
{
    if ($driver === 'sqlite') {
        $path = tempnam(sys_get_temp_dir(), 'mautic-subscription-burst-');
        if (! is_string($path)) {
            throw new RuntimeException('Unable to create the burst database.');
        }
        config()->set('database.connections.subscription-burst', [
            'driver' => 'sqlite', 'database' => $path, 'prefix' => '',
        ]);

        return ['subscription-burst', $path];
    }
    $host = getenv('MAUTIC_TEST_MARIADB_HOST');
    $database = getenv('MAUTIC_TEST_MARIADB_DATABASE');
    if (! is_string($host) || $host === '' || ! is_string($database) || $database === '') {
        throw new RuntimeException('Disposable MariaDB is not configured.');
    }
    if (! str_contains(strtolower($database), 'test')) {
        throw new RuntimeException('MAUTIC_TEST_MARIADB_DATABASE must name a disposable test database.');
    }
    config()->set('database.connections.subscription-burst', [
        'driver' => 'mysql', 'host' => $host,
        'port' => (int) (getenv('MAUTIC_TEST_MARIADB_PORT') ?: 3306), 'database' => $database,
        'username' => getenv('MAUTIC_TEST_MARIADB_USERNAME') ?: 'root',
        'password' => getenv('MAUTIC_TEST_MARIADB_PASSWORD') ?: '',
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
    ]);

    return ['subscription-burst', 'mariadb'];
}

function runSubscriptionBurstWorkers(string $driver, string $path, string $scenario, string $mode): void
{
    $workers = 4;
    $helper = dirname(__DIR__).'/Support/subscription_burst_worker.php';
    $processes = [];
    foreach (range(0, $workers - 1) as $worker) {
        $process = proc_open([
            PHP_BINARY, $helper, $driver, $path, $scenario, (string) $worker, $mode, (string) $workers,
        ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start a burst worker.');
        }
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }
    foreach ($processes as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $stdout."\n".$stderr);
    }
}

function runSubscriptionBurstScenario(string $driver, string $scenario): void
{
    [$connection, $path] = subscriptionBurstDatabase($driver);
    config()->set('database.default', $connection);
    foreach (['identity', 'outbox', 'subscription'] as $section) {
        config()->set('mautic.'.$section.'.database_connection', $connection);
    }
    DB::purge($connection);
    $schema = DB::connection($connection)->getSchemaBuilder();
    $tables = [
        'subscription_burst_events', 'subscription_burst_barriers', 'subscription_burst_memberships',
        'subscription_burst_remote_segments', 'subscription_burst_contacts', 'subscription_burst_control',
        'mautic_subscription_audits', 'mautic_subscription_segment_locks', 'mautic_subscription_segments',
        'mautic_subscription_intents', 'mautic_outbox_connection_blocks', 'mautic_outbox_audits',
        'mautic_outbox_locks', 'mautic_outbox_commands', 'mautic_contact_mappings',
    ];
    foreach ($tables as $table) {
        $schema->dropIfExists($table);
    }

    try {
        (require dirname(__DIR__, 2).'/database/migrations/create_mautic_contact_mappings.php.stub')->up();
        (require dirname(__DIR__, 2).'/database/migrations/create_mautic_contact_outbox.php.stub')->up();
        (require dirname(__DIR__, 2).'/database/migrations/create_mautic_subscription_delivery.php.stub')->up();
        $schema->create('subscription_burst_control', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->unsignedBigInteger('value');
        });
        $schema->create('subscription_burst_contacts', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->string('dnc')->default('');
        });
        $schema->create('subscription_burst_remote_segments', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('alias')->unique();
            $table->boolean('is_global');
        });
        $schema->create('subscription_burst_memberships', function (Blueprint $table): void {
            $table->unsignedBigInteger('contact_id');
            $table->unsignedBigInteger('segment_id');
            $table->unique(['contact_id', 'segment_id']);
        });
        $schema->create('subscription_burst_barriers', function (Blueprint $table): void {
            $table->string('name')->primary();
        });
        $schema->create('subscription_burst_events', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('worker');
        });
        DB::connection($connection)->table('subscription_burst_control')->insert(['name' => 'clock', 'value' => 2_000_000_000]);
        if ($scenario === 'existing') {
            DB::connection($connection)->table('subscription_burst_remote_segments')->insert([
                'name' => 'Burst segment', 'alias' => 'burst-segment', 'is_global' => true,
            ]);
        }
        $clock = new class($connection) implements Clock
        {
            public function __construct(private readonly string $connection) {}

            public function now(): int
            {
                return (int) DB::connection($this->connection)->table('subscription_burst_control')
                    ->where('name', 'clock')->value('value');
            }
        };
        Queue::fake();
        $store = new DatabaseSubscriptionStore(DB::getFacadeRoot(), $clock, $connection);
        $outbox = new DatabaseOutboxStore(DB::getFacadeRoot(), $clock, $connection);
        $mappings = new DatabaseContactMappingRepository(DB::getFacadeRoot(), $connection);
        $delivery = new SubscriptionDelivery(
            $store,
            new ContactOutbox($outbox, new OutboxDispatcher(Queue::getFacadeRoot(), $outbox)),
            $mappings,
            new SubscriptionDispatcher(Queue::getFacadeRoot()),
        );
        $commands = [];
        foreach (range(0, 29) as $index) {
            DB::connection($connection)->table('subscription_burst_control')
                ->where('name', 'clock')->update(['value' => 2_000_000_000 + ($index * 2)]);
            $command = new SubscriptionCommand(
                'main',
                new LocalContactReference('burst-contact', sprintf('%02d', $index)),
                'accepted-'.sprintf('%02d', $index),
                1,
                ContactFields::fromArray([
                    'email' => sprintf('contact-%02d@example.test', $index),
                ], ['email']),
                SegmentTarget::createIfAbsent(
                    'burst-offer', new StaticSegmentDefinition('Burst segment', 'burst-segment'),
                ),
            );
            $captured = DB::connection($connection)->transaction(fn () => $delivery->record($command));
            $commands[] = $command;
        }

        expect(DB::connection($connection)->table('mautic_subscription_intents')->count())->toBe(30);
        runSubscriptionBurstWorkers($driver, $path, $scenario, 'initial');
        $pending = $store->recoverable(100);
        expect(DB::connection($connection)->table('subscription_burst_events')
            ->where('name', 'contender_pending')->count())->toBeGreaterThanOrEqual(3)
            ->and((new SubscriptionPoller($store, new SubscriptionDispatcher(Queue::getFacadeRoot())))
                ->recover(100))->toBe(count($pending));
        for ($round = 0; $round < 5; $round++) {
            runSubscriptionBurstWorkers($driver, $path, $scenario, 'recovery');
            $pending = $store->recoverable(100);
            if ($pending === []) {
                break;
            }
            expect((new SubscriptionPoller($store, new SubscriptionDispatcher(Queue::getFacadeRoot())))
                ->recover(100))->toBe(count($pending));
        }

        $expectedSegmentId = (int) DB::connection($connection)
            ->table('subscription_burst_remote_segments')->value('id');
        $dnc = DB::connection($connection)->table('subscription_burst_contacts')
            ->where('email', 'contact-00@example.test')->value('dnc');
        expect(DB::connection($connection)->table('subscription_burst_contacts')->count())->toBe(30)
            ->and(DB::connection($connection)->table('mautic_contact_mappings')->count())->toBe(30)
            ->and(DB::connection($connection)->table('mautic_contact_mappings')->distinct()->count('remote_id'))->toBe(30)
            ->and(DB::connection($connection)->table('mautic_subscription_intents')->where('status', 'completed')->count())->toBe(30)
            ->and(DB::connection($connection)->table('subscription_burst_memberships')->count())->toBe(30)
            ->and(DB::connection($connection)->table('subscription_burst_memberships')
                ->where('segment_id', $expectedSegmentId)->count())->toBe(30)
            ->and(DB::connection($connection)->table('mautic_subscription_segments')->count())->toBe(1)
            ->and(DB::connection($connection)->table('subscription_burst_events')
                ->where('name', 'segment_create_dispatch')->count())->toBe($scenario === 'missing' ? 1 : 0)
            ->and(DB::connection($connection)->table('mautic_subscription_intents')
                ->whereIn('status', ['failed', 'blocked', 'ambiguous'])->count())->toBe(0)
            ->and($dnc)->toBe('manual-owned-restriction');

        foreach ([$commands[0], $commands[14], $commands[29]] as $command) {
            DB::connection($connection)->transaction(fn () => $delivery->record($command));
        }
        expect(DB::connection($connection)->table('mautic_subscription_intents')->count())->toBe(30)
            ->and(DB::connection($connection)->table('subscription_burst_events')->where('name', 'contact_create')->count())->toBe(30)
            ->and(DB::connection($connection)->table('subscription_burst_events')->where('name', 'membership_add')->count())->toBe(30)
            ->and(DB::connection($connection)->table('subscription_burst_remote_segments')->count())->toBe(1);
    } finally {
        foreach ($tables as $table) {
            $schema->dropIfExists($table);
        }
        DB::purge($connection);
        if ($driver === 'sqlite' && is_file($path)) {
            unlink($path);
        }
    }
}

it('completes existing and missing segment bursts through four SQLite workers', function (string $scenario) {
    runSubscriptionBurstScenario('sqlite', $scenario);
})->with(['existing', 'missing']);

it('completes existing and missing segment bursts through four MariaDB workers', function (string $scenario) {
    if (! is_string(getenv('MAUTIC_TEST_MARIADB_HOST')) || getenv('MAUTIC_TEST_MARIADB_HOST') === ''
        || ! is_string(getenv('MAUTIC_TEST_MARIADB_DATABASE')) || getenv('MAUTIC_TEST_MARIADB_DATABASE') === '') {
        $this->markTestSkipped('Disposable MariaDB is not configured.');
    }
    runSubscriptionBurstScenario('mysql', $scenario);
})->with(['existing', 'missing'])->group('mariadb-subscription-burst');
