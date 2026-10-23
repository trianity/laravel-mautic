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
use Trianity\LaravelMautic\Exceptions\InvalidWriteRequest;
use Trianity\LaravelMautic\MauticManager;
use Trianity\LaravelMautic\Segment\StaticSegmentDefinition;

function useStaticSegmentResponses(array $responses, array &$history = []): void
{
    $handler = HandlerStack::create(new MockHandler($responses));
    $handler->push(Middleware::history($history));
    $http = new Client(['handler' => $handler]);
    app()->instance(HttpClientFactory::class, new class($http) implements HttpClientFactory
    {
        public function __construct(private ClientInterface $client) {}

        public function make(ConnectionConfig $config): ClientInterface
        {
            return $this->client;
        }
    });
    config()->set('mautic.connections.main', [
        'url' => 'https://mautic.example.test',
        'username' => 'segments',
        'password' => 'secret',
    ]);
}

it('reads searches and creates static segments through bounded normalized operations', function () {
    $history = [];
    $segment = ['id' => 9, 'name' => 'Paid subscribers', 'alias' => 'paid-subscribers', 'isPublished' => true, 'isGlobal' => true, 'filters' => []];
    useStaticSegmentResponses([
        new Response(200, ['Content-Type' => 'application/json'], json_encode(['list' => $segment], JSON_THROW_ON_ERROR)),
        new Response(200, ['Content-Type' => 'application/json'], json_encode(['total' => 2, 'lists' => [
            '9' => $segment,
            '10' => ['id' => 10, 'name' => 'Near match', 'alias' => 'paid-subscribers-old', 'isPublished' => true, 'isGlobal' => true, 'filters' => []],
        ]], JSON_THROW_ON_ERROR)),
        new Response(201, ['Content-Type' => 'application/json'], json_encode(['list' => $segment], JSON_THROW_ON_ERROR)),
    ], $history);

    $gateway = app(MauticManager::class)->connection()->staticSegments();
    $found = $gateway->find(9);
    $candidates = $gateway->candidates('paid-subscribers', 10);
    $created = $gateway->create(new StaticSegmentDefinition('Paid subscribers', 'paid-subscribers'));

    expect($found->suitable())->toBeTrue()
        ->and($candidates)->toHaveCount(1)
        ->and($created->remoteId)->toBe(9)
        ->and((string) $history[0]['request']->getUri())->toEndWith('/api/segments/9')
        ->and((string) $history[1]['request']->getUri())->toContain('limit=10')
        ->and($history[2]['request']->getMethod())->toBe('POST')
        ->and(json_decode((string) $history[2]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'name' => 'Paid subscribers',
            'alias' => 'paid-subscribers',
            'isPublished' => true,
            'isGlobal' => true,
            'filters' => [],
        ]);
});

it('rejects a create response that is dynamic unpublished or does not match the definition', function () {
    useStaticSegmentResponses([
        new Response(201, ['Content-Type' => 'application/json'], json_encode(['list' => [
            'id' => 9,
            'name' => 'Changed',
            'alias' => 'paid-subscribers',
            'isPublished' => false,
            'isGlobal' => true,
            'filters' => [['field' => 'email']],
        ]], JSON_THROW_ON_ERROR)),
    ]);

    expect(fn () => app(MauticManager::class)->connection()->staticSegments()->create(
        new StaticSegmentDefinition('Paid subscribers', 'paid-subscribers'),
    ))->toThrow(InvalidWriteRequest::class);
});
