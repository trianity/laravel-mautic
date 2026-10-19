<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Contracts\ReconciliationSource;
use Trianity\LaravelMautic\Exceptions\MauticReadException;
use Trianity\LaravelMautic\Identity\DatabaseContactMappingRepository;
use Trianity\LaravelMautic\Read\ReadFailureKind;
use Trianity\LaravelMautic\Read\ReadOperation;
use Trianity\LaravelMautic\Reconciliation\ContactReconciler;
use Trianity\LaravelMautic\Reconciliation\DatabaseReconciliationStore;
use Trianity\LaravelMautic\Reconciliation\ReconciliationPage;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$databasePath = $argv[1] ?? '';
$mode = $argv[2] ?? '';
$worker = $argv[3] ?? '';
$capsule = new Manager;
$capsule->addConnection(['driver' => 'sqlite', 'database' => $databasePath, 'prefix' => '']);
$capsule->setEventDispatcher(new Dispatcher(new Container));
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
$source = new class($capsule, $worker, $mode) implements ReconciliationSource
{
    public function __construct(
        private Manager $database,
        private string $worker,
        private string $mode,
    ) {}

    public function highWatermark(string $connection): int
    {
        $this->database->table('reconciliation_remote_calls')->insert(['worker' => $this->worker]);
        if ($this->mode === 'handoff-failure') {
            throw new MauticReadException(
                ReadFailureKind::RemoteServer,
                ReadOperation::SearchContacts,
                $connection,
                503,
            );
        }
        usleep(100_000);

        return 0;
    }

    public function page(string $connection, int $fromRemoteId, int $throughRemoteId, int $limit): ReconciliationPage
    {
        throw new LogicException('No page request was expected.');
    }
};

if (str_starts_with($mode, 'handoff-')) {
    $reservationUpdated = false;
    $capsule->getConnection()->listen(function (QueryExecuted $query) use ($capsule, $worker, &$reservationUpdated): void {
        if ($reservationUpdated || ! str_starts_with($query->sql, 'update "mautic_reconciliation_checkpoints"')) {
            return;
        }
        $reservationUpdated = true;
        $capsule->table('reconciliation_ready')->insert(['worker' => $worker.'-reservation-updated']);
        $deadline = microtime(true) + 5;
        while (! $capsule->table('reconciliation_ready')->where('worker', 'release-'.$worker)->exists()
            && microtime(true) < $deadline) {
            usleep(10_000);
        }
    });
    (new ContactReconciler(
        $store,
        $source,
        new DatabaseContactMappingRepository($capsule->getDatabaseManager()),
        $clock,
        minimumPageIntervalSeconds: 0,
        requestReservationSeconds: 5,
    ))->run('main');
} elseif ($mode === 'replacement' || $mode === 'restart-replacement') {
    $checkpoint = $store->begin('main', 10, $mode === 'restart-replacement');
    $reservation = $store->reserve($checkpoint, 10, 5)
        ?? throw new RuntimeException('The replacement worker did not reserve the request.');
    $capsule->table('reconciliation_ready')->insert(['worker' => $worker.'-reserved']);
    $deadline = microtime(true) + 5;
    while (! $capsule->table('reconciliation_ready')->where('worker', 'release-'.$worker)->exists()
        && microtime(true) < $deadline) {
        usleep(10_000);
    }
    try {
        $store->setHighWatermark($reservation, 0);
        $result = 'completed';
    } catch (RuntimeException) {
        $result = 'rejected';
    }
    $capsule->table('reconciliation_results')->insert(['worker' => $worker, 'result' => $result]);
} elseif ($mode === 'race') {
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
