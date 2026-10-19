<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Contracts\ContactSyncTransport;
use Trianity\LaravelMautic\Identity\DatabaseContactMappingRepository;
use Trianity\LaravelMautic\Outbox\DatabaseOutboxStore;
use Trianity\LaravelMautic\Outbox\OutboxProcessor;
use Trianity\LaravelMautic\Write\ContactFields;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$databasePath = $argv[1] ?? '';
$mode = $argv[2] ?? '';
$capsule = new Manager;
$capsule->addConnection(['driver' => 'sqlite', 'database' => $databasePath, 'prefix' => '']);
$capsule->setAsGlobal();
$capsule->getConnection()->statement('PRAGMA busy_timeout = 5000');
$clock = new class($capsule) implements Clock
{
    public function __construct(private Manager $capsule) {}

    public function now(): int
    {
        return (int) $this->capsule->table('outbox_control')->where('name', 'now')->value('value');
    }
};
$transport = new class($capsule, $mode) implements ContactSyncTransport
{
    public function __construct(private Manager $capsule, private string $mode) {}

    public function create(string $connection, ContactFields $fields): int
    {
        throw new LogicException('Create was not expected.');
    }

    public function update(string $connection, int $remoteId, ContactFields $fields): void
    {
        $operationId = $fields->value('firstname') === 'Older' ? 'lease-expiry-v1' : 'lease-expiry-v2';
        $this->capsule->table('outbox_remote_calls')->insert(['worker' => $this->mode, 'operation_id' => $operationId]);
        if ($this->mode !== 'old') {
            return;
        }
        $this->capsule->table('outbox_ready')->insert(['worker' => 'old-in-flight']);
        $deadline = microtime(true) + 5;
        while (! $this->capsule->table('outbox_ready')->where('worker', 'release-old')->exists() && microtime(true) < $deadline) {
            usleep(10_000);
        }
    }
};
$processor = new OutboxProcessor(
    new DatabaseOutboxStore($capsule->getDatabaseManager(), $clock),
    $transport,
    new DatabaseContactMappingRepository($capsule->getDatabaseManager()),
    $clock,
    leaseSeconds: 1,
    maxAttempts: 3,
);

if ($mode === 'old') {
    try {
        $processor->process('lease-expiry-v1');
    } catch (Throwable) {
        // Recovery deliberately owns the terminal ambiguous transition.
    }
} else {
    $processor->process('lease-expiry-v1');
    $processor->process('lease-expiry-v2');
}
