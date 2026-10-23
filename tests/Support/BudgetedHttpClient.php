<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final readonly class BudgetedHttpClient implements ClientInterface
{
    public function __construct(
        private ClientInterface $client,
        private OAuthAcceptanceRequestBudget $budget,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->budget->consume();

        return $this->client->sendRequest($request);
    }
}
