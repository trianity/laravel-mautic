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
use Trianity\LaravelMautic\Write\BatchItemOutcome;
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

it('rejects an exact normalized duplicate before Mautic can merge it', function () {
    $history = [];
    useContactWriteResponses([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'total' => 2,
            'contacts' => [
                ['id' => 7, 'fields' => ['all' => ['email' => 'similar@example.test']]],
            ],
        ], JSON_THROW_ON_ERROR)),
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'total' => 2,
            'contacts' => [
                ['id' => 8, 'fields' => ['all' => ['email' => ' Duplicate+tag@example.test ']]],
            ],
        ], JSON_THROW_ON_ERROR)),
    ], $history);

    expect(fn () => app(MauticManager::class)->connection()->contactWrites()->create(
        ContactFields::fromArray(['email' => 'duplicate+tag@example.test'], ['email']),
    ))->toThrow(ContactAlreadyExists::class)
        ->and($history)->toHaveCount(2);

    parse_str($history[0]['request']->getUri()->getQuery(), $query);
    expect($query['where'][0])->toBe([
        'col' => 'email',
        'expr' => 'eq',
        'val' => 'duplicate+tag@example.test',
    ])->and($query)->not->toHaveKey('start')
        ->and($query['limit'])->toBe('100');

    parse_str($history[1]['request']->getUri()->getQuery(), $secondQuery);
    expect($secondQuery['start'])->toBe('1');
});

it('does not treat a similar search result as a duplicate', function () {
    $history = [];
    useContactWriteResponses([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'total' => 1,
            'contacts' => [
                ['id' => 7, 'fields' => ['all' => ['email' => 'person+other@example.test']]],
            ],
        ], JSON_THROW_ON_ERROR)),
        new Response(201, ['Content-Type' => 'application/json'], contactJson(42, [
            'email' => 'person+tag@example.test',
        ])),
    ], $history);

    $contact = app(MauticManager::class)->connection()->contactWrites()->create(
        ContactFields::fromArray(['email' => 'person+tag@example.test'], ['email']),
    );

    expect($contact->remoteId)->toBe(42)
        ->and($history)->toHaveCount(2)
        ->and($history[1]['request']->getMethod())->toBe('POST');
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

it('normalizes Mautic indexed mixed batch outcomes without discarding successful IDs', function () {
    useContactWriteResponses([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'contacts' => [
                0 => ['id' => 10, 'fields' => ['all' => ['firstname' => 'Ada']]],
                2 => ['id' => 12, 'fields' => ['all' => ['firstname' => 'Lin']]],
            ],
            'statusCodes' => [0 => 201, 1 => 422, 2 => 201],
            'errors' => [1 => ['code' => 422, 'message' => 'private payload invalid']],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $result = app(MauticManager::class)->connection()->contactWrites()->createBatch(
        ContactFields::fromArray(['firstname' => 'Ada'], ['firstname']),
        ContactFields::fromArray(['firstname' => 'Grace'], ['firstname']),
        ContactFields::fromArray(['firstname' => 'Lin'], ['firstname']),
    );

    expect($result->items)->toHaveCount(3)
        ->and($result->items[0]->requestIndex)->toBe(0)
        ->and($result->items[0]->outcome)->toBe(BatchItemOutcome::Successful)
        ->and($result->items[0]->contact?->remoteId)->toBe(10)
        ->and($result->items[1]->requestIndex)->toBe(1)
        ->and($result->items[1]->outcome)->toBe(BatchItemOutcome::Failed)
        ->and($result->items[1]->failureKind)->toBe(FailureKind::Validation)
        ->and($result->items[1]->httpStatus)->toBe(422)
        ->and($result->items[2]->contact?->remoteId)->toBe(12)
        ->and(array_map(fn ($contact) => $contact->remoteId, $result->contacts))->toBe([10, 12]);
});

it('classifies missing targets and incomplete or malformed batch items by original index', function () {
    useContactWriteResponses([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'contacts' => [
                0 => ['id' => 10, 'fields' => ['all' => ['firstname' => 'Ada']]],
                2 => ['id' => 'malformed'],
            ],
            'statusCodes' => [0 => 200, 1 => 404, 2 => 200],
            'errors' => [1 => ['code' => 404, 'message' => 'missing']],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $result = app(MauticManager::class)->connection()->contactWrites()->updateBatch(
        new ContactUpdate(10, ContactFields::fromArray(['firstname' => 'Ada'], ['firstname'])),
        new ContactUpdate(11, ContactFields::fromArray(['firstname' => 'Grace'], ['firstname'])),
        new ContactUpdate(12, ContactFields::fromArray(['firstname' => 'Lin'], ['firstname'])),
        new ContactUpdate(13, ContactFields::fromArray(['firstname' => 'Edsger'], ['firstname'])),
    );

    expect($result->items[0]->outcome)->toBe(BatchItemOutcome::Successful)
        ->and($result->items[1]->outcome)->toBe(BatchItemOutcome::Failed)
        ->and($result->items[1]->failureKind)->toBe(FailureKind::TargetNotFound)
        ->and($result->items[2]->outcome)->toBe(BatchItemOutcome::Unknown)
        ->and($result->items[3]->outcome)->toBe(BatchItemOutcome::Unknown);
});

it('uses PATCH for batch updates and checks every returned item', function () {
    $history = [];
    useContactWriteResponses([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'contacts' => [
                ['id' => 10, 'fields' => ['all' => ['firstname' => 'Ada']]],
                ['id' => 11, 'fields' => ['all' => ['firstname' => 'Grace']]],
            ],
            'statusCodes' => [200, 200],
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
