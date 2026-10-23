<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Trianity\LaravelMautic\Configuration\ConnectionConfig;
use Trianity\LaravelMautic\Contracts\HttpClientFactory;

final readonly class BudgetedHttpClientFactory implements HttpClientFactory
{
    public function __construct(
        private HttpClientFactory $factory,
        private OAuthAcceptanceRequestBudget $budget,
    ) {}

    public function make(ConnectionConfig $config): ClientInterface
    {
        return new BudgetedHttpClient($this->factory->make($config), $this->budget);
    }
}
