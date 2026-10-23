<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
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
use Trianity\LaravelMautic\Tests\TestCase;
use Trianity\LaravelMautic\Write\ContactFields;

uses(TestCase::class);

it('coordinates one segment create attempt across independent processes', function () {
    $databasePath = tempnam(sys_get_temp_dir(), 'mautic-subscription-');
    expect($databasePath)->toBeString();
    $capsule = new Manager;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => $databasePath, 'prefix' => '']);
    $capsule->setAsGlobal();
    $capsule->getConnection()->statement('PRAGMA busy_timeout = 5000');
    $schema = $capsule->schema();
    $schema->create('mautic_subscription_segment_locks', function (Blueprint $table): void {
        $table->char('segment_key_hash', 64)->primary();
        $table->string('connection', 191);
        $table->string('segment_key', 191);
        $table->string('operation_id', 64);
        $table->string('state', 24);
        $table->string('owner', 64);
        $table->unsignedBigInteger('generation');
        $table->unsignedBigInteger('expires_at');
        $table->unsignedBigInteger('remote_id')->nullable();
        $table->string('last_error_code', 64)->nullable();
        $table->timestamps();
    });
    $schema->create('mautic_subscription_segments', function (Blueprint $table): void {
        $table->id();
        $table->string('connection', 191);
        $table->char('connection_hash', 64);
        $table->string('segment_key', 191);
        $table->char('segment_key_hash', 64)->unique();
        $table->unsignedBigInteger('remote_id');
        $table->char('remote_identity_hash', 64)->unique();
        $table->char('definition_hash', 64);
        $table->timestamps();
    });
    $schema->create('subscription_ready', function (Blueprint $table): void {
        $table->string('worker')->primary();
    });
    $schema->create('subscription_create_attempts', function (Blueprint $table): void {
        $table->id();
        $table->string('worker');
    });
    $schema->create('subscription_results', function (Blueprint $table): void {
        $table->string('worker')->primary();
        $table->boolean('acquired');
    });

    $processes = [];
    $helper = dirname(__DIR__).'/Support/subscription_segment_worker.php';
    foreach (['one', 'two'] as $worker) {
        $process = proc_open([PHP_BINARY, $helper, $databasePath, $worker], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        expect($process)->toBeResource();
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

    expect($capsule->table('subscription_create_attempts')->count())->toBe(1)
        ->and($capsule->table('subscription_results')->where('acquired', true)->count())->toBe(1)
        ->and($capsule->table('mautic_subscription_segments')->count())->toBe(1)
        ->and($capsule->table('mautic_subscription_segments')->value('remote_id'))->toBe(91);
    unlink($databasePath);
});

it('blocks a second actual processor after an in-flight create lease expires', function () {
    $databasePath = tempnam(sys_get_temp_dir(), 'mautic-subscription-processor-');
    expect($databasePath)->toBeString();
    config()->set('database.connections.subscription-process', [
        'driver' => 'sqlite', 'database' => $databasePath, 'prefix' => '',
    ]);
    foreach (['identity', 'outbox', 'subscription'] as $section) {
        config()->set('mautic.'.$section.'.database_connection', 'subscription-process');
    }
    DB::purge('subscription-process');
    (require dirname(__DIR__, 2).'/database/migrations/create_mautic_contact_mappings.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/create_mautic_contact_outbox.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/create_mautic_subscription_delivery.php.stub')->up();
    DB::connection('subscription-process')->getSchemaBuilder()->create(
        'subscription_process_control',
        function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->unsignedBigInteger('value');
        },
    );
    DB::connection('subscription-process')->getSchemaBuilder()->create(
        'subscription_process_events',
        function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('worker');
        },
    );
    DB::connection('subscription-process')->table('subscription_process_control')
        ->insert(['name' => 'clock', 'value' => 100]);

    $clock = new class implements Clock
    {
        public function now(): int
        {
            return (int) DB::connection('subscription-process')
                ->table('subscription_process_control')->where('name', 'clock')->value('value');
        }
    };
    Queue::fake();
    $outbox = new DatabaseOutboxStore(DB::getFacadeRoot(), $clock, 'subscription-process');
    $store = new DatabaseSubscriptionStore(DB::getFacadeRoot(), $clock, 'subscription-process');
    $mappings = new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'subscription-process');
    $delivery = new SubscriptionDelivery(
        $store,
        new ContactOutbox($outbox, new OutboxDispatcher(Queue::getFacadeRoot(), $outbox)),
        $mappings,
        new SubscriptionDispatcher(Queue::getFacadeRoot()),
    );
    $commands = [];
    foreach (['first' => 41, 'second' => 42] as $name => $remoteId) {
        $local = new LocalContactReference('process-contact', $name);
        $command = new SubscriptionCommand(
            'main', $local, 'accepted-'.$name, 1,
            ContactFields::fromArray(['email' => $name.'@example.test'], ['email']),
            SegmentTarget::createIfAbsent(
                'concurrent-key', new StaticSegmentDefinition('Concurrent segment', 'concurrent-segment'),
            ),
        );
        $captured = DB::connection('subscription-process')->transaction(fn () => $delivery->record($command));
        DB::connection('subscription-process')->table('mautic_outbox_commands')
            ->where('operation_id', $captured->contactOperationId)
            ->update(['status' => 'completed', 'delivery_stage' => 'remote_succeeded', 'remote_id' => $remoteId]);
        $mappings->claim('main', $local, $remoteId);
        $commands[$name] = $captured->operationId;
    }

    $helper = dirname(__DIR__).'/Support/subscription_processor_worker.php';
    $processes = [];
    foreach (['first', 'second'] as $role) {
        $process = proc_open([PHP_BINARY, $helper, $databasePath, $commands[$role], $role], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        expect($process)->toBeResource();
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

    $block = $store->segmentProvisioningBlock('main', 'concurrent-key');
    expect(DB::connection('subscription-process')->table('subscription_process_events')
        ->where('name', 'create_dispatched')->count())->toBe(1)
        ->and($block?->state)->toBe('ambiguous')
        ->and((int) $block?->remote_id)->toBe(301)
        ->and($store->get($commands['second'])?->lastErrorCode)->toBe('segment_key_provisioning_ambiguous');

    DB::purge('subscription-process');
    unlink($databasePath);
});

it('keeps the durable create marker blocking after the provisioning process is lost', function () {
    $databasePath = tempnam(sys_get_temp_dir(), 'mautic-subscription-lost-');
    expect($databasePath)->toBeString();
    config()->set('database.connections.subscription-process', [
        'driver' => 'sqlite', 'database' => $databasePath, 'prefix' => '',
    ]);
    foreach (['identity', 'outbox', 'subscription'] as $section) {
        config()->set('mautic.'.$section.'.database_connection', 'subscription-process');
    }
    DB::purge('subscription-process');
    (require dirname(__DIR__, 2).'/database/migrations/create_mautic_contact_mappings.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/create_mautic_contact_outbox.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/create_mautic_subscription_delivery.php.stub')->up();
    DB::connection('subscription-process')->getSchemaBuilder()->create(
        'subscription_process_control',
        function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->unsignedBigInteger('value');
        },
    );
    DB::connection('subscription-process')->getSchemaBuilder()->create(
        'subscription_process_events',
        function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('worker');
        },
    );
    DB::connection('subscription-process')->table('subscription_process_control')
        ->insert(['name' => 'clock', 'value' => 100]);
    $clock = new class implements Clock
    {
        public function now(): int
        {
            return (int) DB::connection('subscription-process')
                ->table('subscription_process_control')->where('name', 'clock')->value('value');
        }
    };
    Queue::fake();
    $outbox = new DatabaseOutboxStore(DB::getFacadeRoot(), $clock, 'subscription-process');
    $store = new DatabaseSubscriptionStore(DB::getFacadeRoot(), $clock, 'subscription-process');
    $mappings = new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'subscription-process');
    $delivery = new SubscriptionDelivery(
        $store,
        new ContactOutbox($outbox, new OutboxDispatcher(Queue::getFacadeRoot(), $outbox)),
        $mappings,
        new SubscriptionDispatcher(Queue::getFacadeRoot()),
    );
    $commands = [];
    foreach (['lost' => 51, 'second-lost' => 52] as $name => $remoteId) {
        $local = new LocalContactReference('lost-process-contact', $name);
        $command = new SubscriptionCommand(
            'main', $local, 'accepted-'.$name, 1,
            ContactFields::fromArray(['email' => $name.'@example.test'], ['email']),
            SegmentTarget::createIfAbsent(
                'concurrent-key', new StaticSegmentDefinition('Concurrent segment', 'concurrent-segment'),
            ),
        );
        $captured = DB::connection('subscription-process')->transaction(fn () => $delivery->record($command));
        DB::connection('subscription-process')->table('mautic_outbox_commands')
            ->where('operation_id', $captured->contactOperationId)
            ->update(['status' => 'completed', 'delivery_stage' => 'remote_succeeded', 'remote_id' => $remoteId]);
        $mappings->claim('main', $local, $remoteId);
        $commands[$name] = $captured->operationId;
    }

    $helper = dirname(__DIR__).'/Support/subscription_processor_worker.php';
    foreach (['lost', 'second-lost'] as $role) {
        $process = proc_open([PHP_BINARY, $helper, $databasePath, $commands[$role], $role], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        expect($process)->toBeResource();
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $stdout."\n".$stderr);
    }

    $block = $store->segmentProvisioningBlock('main', 'concurrent-key');
    expect(DB::connection('subscription-process')->table('subscription_process_events')
        ->where('name', 'create_dispatched')->count())->toBe(1)
        ->and($block?->state)->toBe('ambiguous')
        ->and($block?->remote_id)->toBeNull()
        ->and($store->get($commands['second-lost'])?->lastErrorCode)->toBe('segment_key_provisioning_ambiguous');

    DB::purge('subscription-process');
    unlink($databasePath);
});
