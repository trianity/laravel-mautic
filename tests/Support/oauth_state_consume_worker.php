<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use Trianity\LaravelMautic\OAuth\DatabaseOAuthStateRepository;
use Trianity\LaravelMautic\OAuth\SystemClock;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$databasePath = $argv[1] ?? '';
$state = $argv[2] ?? '';
$worker = $argv[3] ?? '';

$capsule = new Manager;
$capsule->addConnection(['driver' => 'sqlite', 'database' => $databasePath, 'prefix' => '']);
$capsule->setAsGlobal();
$capsule->getConnection()->statement('PRAGMA busy_timeout = 5000');
$capsule->table('consume_ready')->insert(['worker' => $worker]);

$deadline = microtime(true) + 5;
while ($capsule->table('consume_ready')->count() < 2 && microtime(true) < $deadline) {
    usleep(10_000);
}

$states = new DatabaseOAuthStateRepository($capsule->getDatabaseManager(), new SystemClock);
$consumed = $states->consume('main', $state, 'trusted-context');
$capsule->table('consume_results')->insert(['worker' => $worker, 'consumed' => $consumed]);
