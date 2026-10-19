<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\OAuth;

use Closure;
use Illuminate\Database\ConnectionResolverInterface;
use Throwable;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Contracts\OAuthRefreshLock;
use Trianity\LaravelMautic\Exceptions\OAuthLockTimeout;

final readonly class DatabaseRefreshLock implements OAuthRefreshLock
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private Clock $clock,
        private ?string $databaseConnection = null,
        private int $pollMilliseconds = 100,
        private string $table = 'mautic_oauth_locks',
    ) {}

    public function block(string $connection, int $waitSeconds, int $leaseSeconds, Closure $callback): mixed
    {
        $database = $this->connections->connection($this->databaseConnection);
        $name = 'refresh:'.hash('sha256', $connection);
        $owner = bin2hex(random_bytes(16));
        $deadline = microtime(true) + $waitSeconds;

        do {
            $database->table($this->table)->where('name', $name)
                ->where('expires_at', '<=', $this->clock->now())->delete();

            $acquired = false;
            try {
                $acquired = $database->table($this->table)->insertOrIgnore([
                    'name' => $name,
                    'owner' => $owner,
                    'expires_at' => $this->clock->now() + $leaseSeconds,
                ]) === 1;
            } catch (Throwable) {
                // A competing database writer may briefly hold the table; retry within the bounded wait.
            }

            if ($acquired) {
                try {
                    return $callback();
                } finally {
                    $database->table($this->table)->where('name', $name)->where('owner', $owner)->delete();
                }
            }

            usleep($this->pollMilliseconds * 1000);
        } while (microtime(true) < $deadline);

        throw new OAuthLockTimeout($connection);
    }
}
