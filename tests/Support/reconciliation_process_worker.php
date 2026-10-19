<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Contracts\ReconciliationSource;
use Trianity\LaravelMautic\Identity\DatabaseContactMappingRepository;
use Trianity\LaravelMautic\Reconciliation\ContactReconciler;
use Trianity\LaravelMautic\Reconciliation\DatabaseReconciliationStore;
use Trianity\LaravelMautic\Reconciliation\ReconciliationPage;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$databasePath = $argv[1] ?? '';
$mode = $argv[2] ?? '';
$worker = $argv[3] ?? '';
$capsule = new Manager;
$capsule->addConnection(['driver' => 'sqlite', 'database' => $databasePath, 'prefix' => '']);
$capsule->setAsGlobal();
$capsule->getConnection()->statement('PRAGMA busy_timeout = 5000');
$clock = new class($capsule) implements Clock
{
    public function __construct(private Manager $database) {}

    public function now(): int
    {
        return (int) $this->database->table('reconciliation_control')->where('name', 'now')->value('value');
    }
};
$store = new DatabaseReconciliationStore($capsule->getDatabaseManager(), $clock);
$source = new class($capsule, $worker) implements ReconciliationSource
{
    public function __construct(private Manager $database, private string $worker) {}

    public function highWatermark(string $connection): int
    {
        $this->database->table('reconciliation_remote_calls')->insert(['worker' => $this->worker]);
        usleep(100_000);

        return 0;
    }

    public function page(string $connection, int $fromRemoteId, int $throughRemoteId, int $limit): ReconciliationPage
    {
        throw new LogicException('No page request was expected.');
    }
};

if ($mode === 'race') {
    $capsule->table('reconciliation_ready')->insert(['worker' => $worker]);
    $deadline = microtime(true) + 5;
    while ($capsule->table('reconciliation_ready')->count() < 2 && microtime(true) < $deadline) {
        usleep(10_000);
    }
    (new ContactReconciler(
        $store,
        $source,
        new DatabaseContactMappingRepository($capsule->getDatabaseManager()),
        $clock,
        requestReservationSeconds: 5,
    ))->run('main');
} elseif ($mode === 'old') {
    $reservation = $store->reserve($store->begin('main', 10), 10, 5)
        ?? throw new RuntimeException('The old worker did not reserve the request.');
    $capsule->table('reconciliation_ready')->insert(['worker' => 'old-reserved']);
    $deadline = microtime(true) + 5;
    while (! $capsule->table('reconciliation_ready')->where('worker', 'release-old')->exists()
        && microtime(true) < $deadline) {
        usleep(10_000);
    }
    $result = [];
    try {
        $store->setHighWatermark($reservation, 10);
        $result[] = 'success-accepted';
    } catch (RuntimeException) {
        $result[] = 'success-rejected';
    }
    try {
        $store->fail($reservation, 'late_failure');
        $result[] = 'failure-accepted';
    } catch (RuntimeException) {
        $result[] = 'failure-rejected';
    }
    $capsule->table('reconciliation_results')->insert(['worker' => 'old', 'result' => implode(',', $result)]);
} else {
    (new ContactReconciler(
        $store,
        $source,
        new DatabaseContactMappingRepository($capsule->getDatabaseManager()),
        $clock,
        minimumPageIntervalSeconds: 10,
        requestReservationSeconds: 5,
    ))->run('main');
}
