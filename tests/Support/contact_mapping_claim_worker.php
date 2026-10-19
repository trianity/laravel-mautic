<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use Trianity\LaravelMautic\Exceptions\ContactMappingConflict;
use Trianity\LaravelMautic\Identity\DatabaseContactMappingRepository;
use Trianity\LaravelMautic\Identity\LocalContactReference;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$databasePath = $argv[1] ?? '';
$worker = $argv[2] ?? '';
$remoteId = (int) ($argv[3] ?? 0);

$capsule = new Manager;
$capsule->addConnection(['driver' => 'sqlite', 'database' => $databasePath, 'prefix' => '']);
$capsule->setAsGlobal();
$capsule->getConnection()->statement('PRAGMA busy_timeout = 5000');
$capsule->table('mapping_ready')->insert(['worker' => $worker]);

$deadline = microtime(true) + 5;
while ($capsule->table('mapping_ready')->count() < 2 && microtime(true) < $deadline) {
    usleep(10_000);
}

$repository = new DatabaseContactMappingRepository($capsule->getDatabaseManager());
try {
    $repository->claim(
        'main',
        new LocalContactReference('tenant:acme:crm-contact', 'contact-01'),
        $remoteId,
    );
    $result = 'claimed';
} catch (ContactMappingConflict) {
    $result = 'conflict';
}

$capsule->table('mapping_results')->insert(['worker' => $worker, 'result' => $result]);
