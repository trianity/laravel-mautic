<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Trianity\LaravelMautic\Configuration\ConnectionConfig;
use Trianity\LaravelMautic\Contracts\HttpClientFactory;
use Trianity\LaravelMautic\MauticManager;

it('uses the real upstream client to retrieve contact segments with isolated credentials', function () {
    $history = [];
    $handler = HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], '{"lists":{"3":{"id":3,"name":"Customers"}}}'),
        new Response(200, ['Content-Type' => 'application/json'], '{"lists":{}}'),
    ]));
    $handler->push(Middleware::history($history));
    $http = new Client(['handler' => $handler]);

    $this->app->instance(HttpClientFactory::class, new class($http) implements HttpClientFactory
    {
        public function __construct(private ClientInterface $client) {}

        public function make(ConnectionConfig $config): ClientInterface
        {
            return $this->client;
        }
    });

    config()->set('mautic.connections', [
        'main' => ['url' => 'https://first.example.test/marketing', 'username' => 'one', 'password' => 'first-secret'],
        'second' => ['url' => 'https://second.example.test', 'username' => 'two', 'password' => 'second-secret'],
    ]);

    $manager = app(MauticManager::class);
    $response = $manager->connection()->contacts()->getContactSegments(42);
    $manager->connection('second')->contacts()->getContactSegments(7);

    expect($response['lists'][3]['name'])->toBe('Customers');
    expect((string) $history[0]['request']->getUri())->toBe('https://first.example.test/marketing/api/contacts/42/segments');
    expect((string) $history[1]['request']->getUri())->toBe('https://second.example.test/api/contacts/7/segments');
    expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('Basic '.base64_encode('one:first-secret'));
    expect($history[1]['request']->getHeaderLine('Authorization'))->toBe('Basic '.base64_encode('two:second-secret'));
});
