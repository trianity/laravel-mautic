<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use Trianity\LaravelMautic\OAuth\SystemClock;
use Trianity\LaravelMautic\Subscription\DatabaseSubscriptionStore;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$databasePath = $argv[1] ?? '';
$worker = $argv[2] ?? '';
$capsule = new Manager;
$capsule->addConnection(['driver' => 'sqlite', 'database' => $databasePath, 'prefix' => '']);
$capsule->setAsGlobal();
$capsule->getConnection()->statement('PRAGMA busy_timeout = 5000');
$capsule->table('subscription_ready')->insert(['worker' => $worker]);
$deadline = microtime(true) + 5;
while ($capsule->table('subscription_ready')->count() < 2 && microtime(true) < $deadline) {
    usleep(10_000);
}

$store = new DatabaseSubscriptionStore($capsule->getDatabaseManager(), new SystemClock);
$acquired = $store->acquireSegment('main', 'shared-business-key', $worker, 5);
if ($acquired) {
    $capsule->table('subscription_create_attempts')->insert(['worker' => $worker]);
    usleep(200_000);
    $store->associateSegment('main', 'shared-business-key', 91, str_repeat('a', 64));
    $store->releaseSegment('main', 'shared-business-key', $worker);
}
$capsule->table('subscription_results')->insert(['worker' => $worker, 'acquired' => $acquired]);
