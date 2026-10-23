<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Config\Repository;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Trianity\LaravelMautic\Configuration\ConnectionConfig;
use Trianity\LaravelMautic\Contracts\HttpClientFactory;
use Trianity\LaravelMautic\Contracts\OAuthClientCredentialsProvider;
use Trianity\LaravelMautic\Data\OAuthClientCredentials;
use Trianity\LaravelMautic\Data\OAuthToken;
use Trianity\LaravelMautic\OAuth\HttpOAuthTokenEndpoint;
use Trianity\LaravelMautic\OAuth\SystemClock;
use Trianity\LaravelMautic\Support\ApiLibraryConnectionFactory;
use Trianity\LaravelMautic\Tests\Support\BudgetedHttpClient;
use Trianity\LaravelMautic\Tests\Support\BudgetedHttpClientFactory;
use Trianity\LaravelMautic\Tests\Support\OAuthAcceptanceRequestBudget;
use Trianity\LaravelMautic\Write\ContactFields;

it('forwards requests through the limit and rejects the next before transport', function () {
    $transport = new class implements ClientInterface
    {
        public int $attempts = 0;

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $this->attempts++;

            return new Response(200);
        }
    };
    $budget = new OAuthAcceptanceRequestBudget(operationLimit: 2, cleanupLimit: 1);
    $client = new BudgetedHttpClient($transport, $budget);
    $request = new Request(
        'POST',
        'https://mautic.test/api/contacts/new',
        ['Authorization' => 'Bearer should-never-appear'],
        'private-contact-payload',
    );

    $client->sendRequest($request);
    $client->sendRequest($request);

    try {
        $client->sendRequest($request);
        test()->fail('The request beyond the operation limit was not rejected.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())
            ->toBe('OAuth acceptance operation request budget exhausted.')
            ->not->toContain('should-never-appear', 'private-contact-payload');
    }

    expect($transport->attempts)->toBe(2)
        ->and($budget->operationAttempts())->toBe(2);
});

it('counts transport failures before forwarding', function () {
    $transport = new class implements ClientInterface
    {
        public int $attempts = 0;

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $this->attempts++;

            throw new RuntimeException('transport failed');
        }
    };
    $budget = new OAuthAcceptanceRequestBudget(operationLimit: 2, cleanupLimit: 1);

    expect(fn () => (new BudgetedHttpClient($transport, $budget))->sendRequest(
        new Request('GET', 'https://mautic.test/api/contacts/1'),
    ))->toThrow(RuntimeException::class, 'transport failed')
        ->and($transport->attempts)->toBe(1)
        ->and($budget->operationAttempts())->toBe(1);
});

it('shares operation budget across clients and enforces independent cleanup allowance', function () {
    $transport = new class implements ClientInterface
    {
        public int $attempts = 0;

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $this->attempts++;

            return new Response(200);
        }
    };
    $delegate = new class($transport) implements HttpClientFactory
    {
        public function __construct(private ClientInterface $client) {}

        public function make(ConnectionConfig $config): ClientInterface
        {
            return $this->client;
        }
    };
    $budget = new OAuthAcceptanceRequestBudget(operationLimit: 1, cleanupLimit: 1);
    $factory = new BudgetedHttpClientFactory($delegate, $budget);
    $config = ConnectionConfig::fromArray('main', [
        'url' => 'https://mautic.test',
        'username' => 'fixture',
        'password' => 'not-inspected',
    ]);
    $first = $factory->make($config);
    $second = $factory->make($config);
    $request = new Request('GET', 'https://mautic.test/api/contacts/1');

    $first->sendRequest($request);
    expect(fn () => $second->sendRequest($request))
        ->toThrow(RuntimeException::class, 'operation request budget exhausted');

    $budget->beginCleanup();
    $second->sendRequest($request);
    expect(fn () => $first->sendRequest($request))
        ->toThrow(RuntimeException::class, 'cleanup request budget exhausted');

    expect($transport->attempts)->toBe(2)
        ->and($budget->operationAttempts())->toBe(1)
        ->and($budget->cleanupAttempts())->toBe(1);
});

it('measures SDK extra requests and OAuth token requests through one factory budget', function () {
    $history = [];
    $handler = HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], '{"total":0,"contacts":[]}'),
        new Response(201, ['Content-Type' => 'application/json'], json_encode([
            'contact' => ['id' => 42, 'fields' => ['all' => ['email' => 'fixture@example.test']]],
        ], JSON_THROW_ON_ERROR)),
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => 'rotated-access',
            'refresh_token' => 'rotated-refresh',
            'expires_in' => 3600,
            'token_type' => 'bearer',
        ], JSON_THROW_ON_ERROR)),
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => 'cleanup-access',
            'refresh_token' => 'cleanup-refresh',
            'expires_in' => 3600,
            'token_type' => 'bearer',
        ], JSON_THROW_ON_ERROR)),
    ]));
    $handler->push(Middleware::history($history));
    $transport = new Client(['handler' => $handler]);
    $delegate = new class($transport) implements HttpClientFactory
    {
        public function __construct(private ClientInterface $client) {}

        public function make(ConnectionConfig $config): ClientInterface
        {
            return $this->client;
        }
    };
    $budget = new OAuthAcceptanceRequestBudget(operationLimit: 4, cleanupLimit: 1);
    $clients = new BudgetedHttpClientFactory($delegate, $budget);
    $apiConfig = ConnectionConfig::fromArray('api', [
        'url' => 'https://mautic.test',
        'username' => 'fixture',
        'password' => 'secret',
    ]);

    (new ApiLibraryConnectionFactory($clients))->make($apiConfig)->contactWrites()->create(
        ContactFields::fromArray(['email' => 'fixture@example.test'], ['email']),
    );

    $config = new Repository(['mautic' => ['connections' => ['oauth' => [
        'url' => 'https://mautic.test',
        'auth' => 'oauth2',
        'oauth' => [
            'client_id' => 'client-id',
            'client_secret_env' => 'IGNORED_BY_TEST_PROVIDER',
            'redirect_uri' => 'https://app.example.test/callback',
        ],
    ]]]]);
    $credentials = new class implements OAuthClientCredentialsProvider
    {
        public function forConnection(string $connection): OAuthClientCredentials
        {
            return new OAuthClientCredentials('client-id', 'client-secret');
        }
    };
    $endpoint = new HttpOAuthTokenEndpoint($config, $clients, $credentials, new SystemClock);
    $rotated = $endpoint->refresh(
        'oauth',
        new OAuthToken('old-access', 'old-refresh', 1),
    );
    $budget->beginCleanup();
    $endpoint->refresh('oauth', $rotated);

    expect($budget->operationAttempts())->toBe(3)
        ->and($budget->cleanupAttempts())->toBe(1)
        ->and($history)->toHaveCount(4)
        ->and((string) $history[0]['request']->getUri())->toContain('/api/contacts')
        ->and((string) $history[1]['request']->getUri())->toContain('/api/contacts/new')
        ->and((string) $history[2]['request']->getUri())->toBe('https://mautic.test/oauth/v2/token')
        ->and((string) $history[3]['request']->getUri())->toBe('https://mautic.test/oauth/v2/token');
});
