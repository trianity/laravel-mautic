<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use Illuminate\Encryption\Encrypter;
use Trianity\LaravelMautic\Contracts\OAuthTokenEndpoint;
use Trianity\LaravelMautic\Data\OAuthToken;
use Trianity\LaravelMautic\OAuth\DatabaseOAuthTokenRepository;
use Trianity\LaravelMautic\OAuth\DatabaseRefreshLock;
use Trianity\LaravelMautic\OAuth\OAuthTokenManager;
use Trianity\LaravelMautic\OAuth\SystemClock;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$databasePath = $argv[1] ?? '';
$worker = $argv[2] ?? '';
$capsule = new Manager;
$capsule->addConnection(['driver' => 'sqlite', 'database' => $databasePath, 'prefix' => '']);
$capsule->setAsGlobal();
$capsule->getConnection()->statement('PRAGMA busy_timeout = 5000');
$capsule->table('refresh_ready')->insert(['worker' => $worker]);

$deadline = microtime(true) + 5;
while ($capsule->table('refresh_ready')->count() < 3 && microtime(true) < $deadline) {
    usleep(10_000);
}

$repository = new DatabaseOAuthTokenRepository(
    $capsule->getDatabaseManager(),
    new Encrypter(str_repeat('k', 32), 'AES-256-CBC'),
);
$endpoint = new class($capsule) implements OAuthTokenEndpoint
{
    public function __construct(private readonly Manager $database) {}

    public function exchange(string $connection, string $code): OAuthToken
    {
        throw new LogicException('Not used.');
    }

    public function refresh(string $connection, OAuthToken $token): OAuthToken
    {
        $this->database->table('refresh_calls')->insert(['connection' => $connection]);
        usleep(100_000);

        return new OAuthToken('fresh-access', 'rotated-refresh', time() + 3600);
    }
};
$manager = new OAuthTokenManager(
    $repository,
    $endpoint,
    new DatabaseRefreshLock($capsule->getDatabaseManager(), new SystemClock, null, 10),
    new SystemClock,
    0,
    5,
    3,
);
$manager->validToken('main');
