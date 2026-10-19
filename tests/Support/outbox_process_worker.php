<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use Trianity\LaravelMautic\Contracts\ContactSyncTransport;
use Trianity\LaravelMautic\Identity\DatabaseContactMappingRepository;
use Trianity\LaravelMautic\OAuth\SystemClock;
use Trianity\LaravelMautic\Outbox\DatabaseOutboxStore;
use Trianity\LaravelMautic\Outbox\OutboxProcessor;
use Trianity\LaravelMautic\Write\ContactFields;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$databasePath = $argv[1] ?? '';
$worker = $argv[2] ?? '';
$capsule = new Manager;
$capsule->addConnection(['driver' => 'sqlite', 'database' => $databasePath, 'prefix' => '']);
$capsule->setAsGlobal();
$capsule->getConnection()->statement('PRAGMA busy_timeout = 5000');
$capsule->table('outbox_ready')->insert(['worker' => $worker]);
$deadline = microtime(true) + 5;
while ($capsule->table('outbox_ready')->count() < 2 && microtime(true) < $deadline) {
    usleep(10_000);
}

$transport = new class($capsule, $worker) implements ContactSyncTransport
{
    public function __construct(private Manager $capsule, private string $worker) {}

    public function create(string $connection, ContactFields $fields): int
    {
        throw new LogicException('Create was not expected.');
    }

    public function update(string $connection, int $remoteId, ContactFields $fields): void
    {
        $this->capsule->table('outbox_remote_calls')->insert(['worker' => $this->worker]);
        usleep(200_000);
    }
};
$processor = new OutboxProcessor(
    new DatabaseOutboxStore($capsule->getDatabaseManager(), new SystemClock),
    $transport,
    new DatabaseContactMappingRepository($capsule->getDatabaseManager()),
    new SystemClock,
    leaseSeconds: 5,
    maxAttempts: 3,
);
$processor->process('process-race');
$status = $capsule->table('mautic_outbox_commands')->value('status');
$capsule->table('outbox_results')->insert(['worker' => $worker, 'result' => is_string($status) ? $status : 'unknown']);
