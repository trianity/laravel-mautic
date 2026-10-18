<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic;

use Illuminate\Contracts\Config\Repository;
use Trianity\LaravelMautic\Configuration\ConnectionConfig;
use Trianity\LaravelMautic\Contracts\ConnectionFactory;
use Trianity\LaravelMautic\Exceptions\InvalidConfiguration;

final class MauticManager
{
    /** @var array<string, MauticConnection> */
    private array $connections = [];

    public function __construct(
        private readonly Repository $config,
        private readonly ConnectionFactory $factory,
    ) {}

    public function connection(?string $name = null): MauticConnection
    {
        $name ??= $this->defaultConnectionName();

        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        }

        $connections = $this->config->get('mautic.connections', []);
        $values = is_array($connections) ? ($connections[$name] ?? null) : null;

        if (! is_array($values)) {
            throw new InvalidConfiguration('The requested Mautic connection is not configured.');
        }

        foreach (array_keys($values) as $key) {
            if (! is_string($key)) {
                throw new InvalidConfiguration('Mautic connection configuration requires named keys.');
            }
        }

        return $this->connections[$name] = $this->factory->make(ConnectionConfig::fromArray($name, $values));
    }

    public function purge(?string $name = null): void
    {
        unset($this->connections[$name ?? $this->defaultConnectionName()]);
    }

    public function forgetConnections(): void
    {
        $this->connections = [];
    }

    private function defaultConnectionName(): string
    {
        $name = $this->config->get('mautic.default');

        if (! is_string($name) || trim($name) === '') {
            throw new InvalidConfiguration('A default Mautic connection name is required.');
        }

        return $name;
    }
}
