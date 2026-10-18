<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Trianity\LaravelMautic\Configuration\ConnectionConfig;
use Trianity\LaravelMautic\Contracts\HttpClientFactory;
use Trianity\LaravelMautic\Exceptions\AmbiguousMutationOutcome;
use Trianity\LaravelMautic\Exceptions\ContactAlreadyExists;
use Trianity\LaravelMautic\Exceptions\ContactUpdateTargetNotFound;
use Trianity\LaravelMautic\Exceptions\MauticWriteException;
use Trianity\LaravelMautic\MauticManager;
use Trianity\LaravelMautic\Write\ContactFields;
use Trianity\LaravelMautic\Write\ContactUpdate;
use Trianity\LaravelMautic\Write\FailureKind;

function useContactWriteResponses(array $responses, array &$history = []): void
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
        'username' => 'writer',
        'password' => 'top-secret',
    ]);
}

function contactJson(int $id, array $fields): string
{
    return json_encode(['contact' => ['id' => $id, 'fields' => ['all' => $fields]]], JSON_THROW_ON_ERROR);
}

it('uses POST for creation, PATCH without create-if-missing for updates, and preserves explicit values', function () {
    $history = [];
    useContactWriteResponses([
        new Response(200, ['Content-Type' => 'application/json'], '{"total":0,"contacts":[]}'),
        new Response(201, ['Content-Type' => 'application/json'], contactJson(42, ['email' => 'person@example.test'])),
        new Response(200, ['Content-Type' => 'application/json'], contactJson(42, [
            'firstname' => null,
            'subscribed' => false,
            'score' => 0,
            'company' => '',
        ])),
        new Response(200, ['Content-Type' => 'application/json'], '{"success":true}'),
        new Response(200, ['Content-Type' => 'application/json'], '{"success":true}'),
    ], $history);

    $writer = app(MauticManager::class)->connection()->contactWrites();
    $writer->create(ContactFields::fromArray(['email' => 'person@example.test'], ['email']));
    $writer->update(42, ContactFields::fromArray([
        'firstname' => null,
        'subscribed' => false,
        'score' => 0,
        'company' => '',
    ], ['firstname', 'subscribed', 'score', 'company']));
    $writer->addToSegment(42, 9);
    $writer->removeFromSegment(42, 9);

    expect($history[1]['request']->getMethod())->toBe('POST')
        ->and((string) $history[1]['request']->getUri())->toBe('https://mautic.example.test/api/contacts/new')
        ->and(json_decode((string) $history[1]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'email' => 'person@example.test',
        ])
        ->and($history[2]['request']->getMethod())->toBe('PATCH')
        ->and((string) $history[2]['request']->getUri())->toBe('https://mautic.example.test/api/contacts/42/edit')
        ->and(json_decode((string) $history[2]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'firstname' => null,
            'subscribed' => false,
            'score' => 0,
            'company' => '',
        ])
        ->and((string) $history[3]['request']->getUri())->toBe('https://mautic.example.test/api/segments/9/contact/42/add')
        ->and($history[3]['request']->getMethod())->toBe('POST')
        ->and((string) $history[3]['request']->getBody())->toBe('[]')
        ->and((string) $history[4]['request']->getUri())->toBe('https://mautic.example.test/api/segments/9/contact/42/remove')
        ->and($history[4]['request']->getMethod())->toBe('POST')
        ->and((string) $history[4]['request']->getBody())->toBe('[]');
});

it('rejects a known duplicate before Mautic can merge it', function () {
    $history = [];
    useContactWriteResponses([
        new Response(200, ['Content-Type' => 'application/json'], '{"total":1,"contacts":{"7":{"id":7}}}'),
    ], $history);

    expect(fn () => app(MauticManager::class)->connection()->contactWrites()->create(
        ContactFields::fromArray(['email' => 'duplicate@example.test'], ['email']),
    ))->toThrow(ContactAlreadyExists::class)
        ->and($history)->toHaveCount(1);
});

it('does not create on a missing PATCH target', function () {
    useContactWriteResponses([
        new Response(404, ['Content-Type' => 'application/json'], '{"errors":[{"code":404,"message":"missing"}]}'),
    ]);

    expect(fn () => app(MauticManager::class)->connection()->contactWrites()->update(
        404,
        ContactFields::fromArray(['firstname' => 'Ada'], ['firstname']),
    ))->toThrow(ContactUpdateTargetNotFound::class);
});

it('normalizes create and update payload errors without exposing remote messages', function (string $method) {
    $responses = [];
    if ($method === 'create') {
        $responses[] = new Response(200, ['Content-Type' => 'application/json'], '{"total":0,"contacts":[]}');
    }
    $responses[] = new Response(200, ['Content-Type' => 'application/json'], '{"errors":[{"code":422,"message":"private payload invalid"}]}');
    useContactWriteResponses($responses);

    $call = fn () => $method === 'create'
        ? app(MauticManager::class)->connection()->contactWrites()->create(
            ContactFields::fromArray(['email' => 'person@example.test'], ['email']),
        )
        : app(MauticManager::class)->connection()->contactWrites()->update(
            42,
            ContactFields::fromArray(['firstname' => 'Ada'], ['firstname']),
        );

    expect($call)->toThrow(function (MauticWriteException $exception) {
        return $exception->kind === FailureKind::Validation
            && ! str_contains($exception->getMessage(), 'private payload');
    });
})->with(['create', 'update']);

it('surfaces a connection loss during create as ambiguous and does not retry', function () {
    $history = [];
    useContactWriteResponses([
        new Response(200, ['Content-Type' => 'application/json'], '{"total":0,"contacts":[]}'),
        new ConnectException('Connection lost after send', new Request('POST', 'https://mautic.example.test/api/contacts/new')),
    ], $history);

    expect(fn () => app(MauticManager::class)->connection()->contactWrites()->create(
        ContactFields::fromArray(['email' => 'private@example.test'], ['email']),
    ))->toThrow(function (AmbiguousMutationOutcome $exception) {
        return $exception->kind === FailureKind::AmbiguousOutcome
            && ! str_contains($exception->getMessage(), 'private@example.test');
    })->and($history)->toHaveCount(2);
});

it('checks errors inside every batch response item', function () {
    useContactWriteResponses([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'contacts' => [
                ['id' => 10, 'fields' => ['all' => ['firstname' => 'Ada']]],
                ['errors' => [['code' => 422, 'message' => 'private payload invalid']]],
            ],
        ], JSON_THROW_ON_ERROR)),
    ]);

    try {
        app(MauticManager::class)->connection()->contactWrites()->createBatch(
            ContactFields::fromArray(['firstname' => 'Ada'], ['firstname']),
            ContactFields::fromArray(['firstname' => 'Grace'], ['firstname']),
        );
        test()->fail('Expected the second batch item to fail.');
    } catch (MauticWriteException $exception) {
        expect($exception->kind)->toBe(FailureKind::Validation)
            ->and($exception->batchIndex)->toBe(1)
            ->and($exception->getMessage())->not->toContain('private payload');
    }
});

it('uses PATCH for batch updates and checks every returned item', function () {
    $history = [];
    useContactWriteResponses([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'contacts' => [
                ['id' => 10, 'fields' => ['all' => ['firstname' => 'Ada']]],
                ['id' => 11, 'fields' => ['all' => ['firstname' => 'Grace']]],
            ],
        ], JSON_THROW_ON_ERROR)),
    ], $history);

    $result = app(MauticManager::class)->connection()->contactWrites()->updateBatch(
        new ContactUpdate(10, ContactFields::fromArray(['firstname' => 'Ada'], ['firstname'])),
        new ContactUpdate(11, ContactFields::fromArray(['firstname' => 'Grace'], ['firstname'])),
    );

    expect($result->contacts)->toHaveCount(2)
        ->and($history[0]['request']->getMethod())->toBe('PATCH')
        ->and((string) $history[0]['request']->getUri())->toBe('https://mautic.example.test/api/contacts/batch/edit');
});
