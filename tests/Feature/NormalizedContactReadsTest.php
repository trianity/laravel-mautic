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
use Trianity\LaravelMautic\Data\BounceEvidence;
use Trianity\LaravelMautic\Data\DoNotContactReason;
use Trianity\LaravelMautic\Data\ValuePresence;
use Trianity\LaravelMautic\Exceptions\ContactNotFound;
use Trianity\LaravelMautic\Exceptions\MauticReadException;
use Trianity\LaravelMautic\MauticManager;
use Trianity\LaravelMautic\Read\ContactSearch;
use Trianity\LaravelMautic\Read\ReadFailureKind;
use Trianity\LaravelMautic\Read\ReadOperation;

function useContactReadResponses(array $responses, array &$history = []): void
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
        'username' => 'reader',
        'password' => 'top-secret',
    ]);
}

it('maps real SDK contact search and memberships into typed results', function () {
    $history = [];
    useContactReadResponses([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'total' => '2',
            'contacts' => [
                '42' => [
                    'id' => 42,
                    'fields' => ['all' => ['email' => 'one@example.test', 'phone' => null, 'company' => '', 'host_reference' => 'custom-42']],
                    'tags' => [['id' => 4, 'tag' => 'Customer']],
                    'doNotContact' => [[
                        'id' => 7,
                        'channel' => 'email',
                        'reason' => 2,
                        'dateAdded' => '2026-10-23T08:30:00+00:00',
                        'channelId' => 19,
                        'comments' => null,
                    ]],
                ],
                '43' => ['id' => 43],
            ],
        ], JSON_THROW_ON_ERROR)),
        new Response(200, ['Content-Type' => 'application/json'], '{"total":"1","lists":{"9":{"id":9,"name":"Customers","alias":"customers"}}}'),
    ], $history);

    $reader = app(MauticManager::class)->connection()->contactReads();
    $page = $reader->search(new ContactSearch('email:one@example.test', 0, 25));
    $memberships = $reader->segments(42);

    expect($page->total)->toBe(2)
        ->and($page->offset)->toBe(0)
        ->and($page->limit)->toBe(25)
        ->and($page->contacts)->toHaveCount(2)
        ->and($page->contacts[0]->field('email')->value())->toBe('one@example.test')
        ->and($page->contacts[0]->field('phone')->presence)->toBe(ValuePresence::Null)
        ->and($page->contacts[0]->field('company')->value())->toBe('')
        ->and($page->contacts[0]->field('host_reference')->value())->toBe('custom-42')
        ->and($page->contacts[0]->tags->value()[0]->name)->toBe('Customer')
        ->and($page->contacts[0]->doNotContact->value()[0]->remoteId->value())->toBe(7)
        ->and($page->contacts[0]->doNotContact->value()[0]->reason->value())->toBe(2)
        ->and($page->contacts[0]->doNotContact->value()[0]->knownReason())->toBe(DoNotContactReason::Bounced)
        ->and($page->contacts[0]->doNotContact->value()[0]->bounceEvidence())->toBe(BounceEvidence::SubtypeUnavailable)
        ->and($page->contacts[0]->doNotContact->value()[0]->dateAdded->value())->toBe('2026-10-23T08:30:00+00:00')
        ->and($page->contacts[0]->doNotContact->value()[0]->channelId->value())->toBe(19)
        ->and($page->contacts[0]->doNotContact->value()[0]->comments->presence)->toBe(ValuePresence::Null)
        ->and($page->contacts[1]->tags->presence)->toBe(ValuePresence::Missing)
        ->and($memberships->memberships[0]->name)->toBe('Customers')
        ->and((string) $history[0]['request']->getUri())->toContain('limit=25')
        ->and(ReadOperation::SearchContacts->safety()->mayRetry)->toBeTrue();
});

it('preserves empty unavailable and unknown restriction states without inventing bounce evidence', function () {
    useContactReadResponses([
        new Response(200, ['Content-Type' => 'application/json'], '{"contact":{"id":40,"doNotContact":[]}}'),
        new Response(200, ['Content-Type' => 'application/json'], '{"contact":{"id":41}}'),
        new Response(200, ['Content-Type' => 'application/json'], '{"contact":{"id":42,"doNotContact":null}}'),
        new Response(200, ['Content-Type' => 'application/json'], '{"contact":{"id":43,"doNotContact":[{"channel":"email","reason":0},{"channel":"sms","reason":1},{"channel":"email","reason":"2"},{"channel":"email","reason":3},{"channel":"email","reason":99}]}}'),
    ]);

    $reader = app(MauticManager::class)->connection()->contactReads();
    $empty = $reader->find(40);
    $missing = $reader->find(41);
    $unavailable = $reader->find(42);
    $restrictions = $reader->find(43)->doNotContact->value();
    $unknown = $restrictions[4];

    expect($empty->doNotContact->presence)->toBe(ValuePresence::Value)
        ->and($empty->doNotContact->value())->toBe([])
        ->and($missing->doNotContact->presence)->toBe(ValuePresence::Missing)
        ->and($unavailable->doNotContact->presence)->toBe(ValuePresence::Null)
        ->and(array_map(static fn ($entry) => $entry->knownReason(), array_slice($restrictions, 0, 4)))
        ->toBe([
            DoNotContactReason::Contactable,
            DoNotContactReason::Unsubscribed,
            DoNotContactReason::Bounced,
            DoNotContactReason::Manual,
        ])
        ->and($unknown->reason->value())->toBe(99)
        ->and($unknown->knownReason())->toBeNull()
        ->and($unknown->bounceEvidence())->toBe(BounceEvidence::Unavailable)
        ->and($unknown->remoteId->presence)->toBe(ValuePresence::Missing)
        ->and($unknown->dateAdded->presence)->toBe(ValuePresence::Missing)
        ->and($unknown->channelId->presence)->toBe(ValuePresence::Missing)
        ->and($unknown->comments->presence)->toBe(ValuePresence::Missing);
});

it('rejects malformed restriction members instead of reporting an empty restriction set', function (array $entry) {
    useContactReadResponses([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'contact' => ['id' => 42, 'doNotContact' => [$entry]],
        ], JSON_THROW_ON_ERROR)),
    ]);

    expect(fn () => app(MauticManager::class)->connection()->contactReads()->find(42))
        ->toThrow(fn (MauticReadException $exception) => $exception->kind === ReadFailureKind::UnexpectedResponse);
})->with([
    'missing channel' => [['reason' => 2]],
    'malformed ID' => [['id' => '7', 'channel' => 'email']],
    'malformed date' => [['channel' => 'email', 'dateAdded' => false]],
    'malformed channel ID' => [['channel' => 'email', 'channelId' => '19']],
    'malformed comments' => [['channel' => 'email', 'comments' => []]],
    'malformed reason' => [['channel' => 'email', 'reason' => []]],
]);

it('distinguishes a missing contact from an empty membership', function () {
    useContactReadResponses([
        new Response(404, ['Content-Type' => 'application/json'], '{"errors":[{"code":404,"message":"missing"}]}'),
        new Response(200, ['Content-Type' => 'application/json'], '{"total":0,"lists":[]}'),
    ]);

    $reader = app(MauticManager::class)->connection()->contactReads();

    expect(fn () => $reader->find(404))->toThrow(ContactNotFound::class)
        ->and($reader->segments(42)->memberships)->toBe([]);
});

it('normalizes SDK payload and transport failures without leaking response data', function (mixed $response, ReadFailureKind $kind) {
    useContactReadResponses([$response]);

    try {
        app(MauticManager::class)->connection()->contactReads()->find(42);
        test()->fail('Expected a normalized read exception.');
    } catch (MauticReadException $exception) {
        expect($exception->kind)->toBe($kind)
            ->and($exception->getMessage())->not->toContain('top-secret')
            ->and($exception->getMessage())->not->toContain('private@example.test');
    }
})->with([
    'HTTP 200 error payload' => [
        new Response(200, ['Content-Type' => 'application/json'], '{"errors":[{"code":401,"message":"private@example.test denied"}]}'),
        ReadFailureKind::Authentication,
    ],
    'validation' => [
        new Response(422, ['Content-Type' => 'application/json'], '{"errors":[{"code":422,"message":"private@example.test invalid"}]}'),
        ReadFailureKind::Validation,
    ],
    'throttling' => [
        new Response(429, ['Content-Type' => 'application/json'], '{"errors":[{"code":429,"message":"slow down"}]}'),
        ReadFailureKind::Throttled,
    ],
    'malformed JSON' => [
        new Response(200, ['Content-Type' => 'application/json'], '{private@example.test'),
        ReadFailureKind::MalformedResponse,
    ],
    'timeout' => [
        new ConnectException('Connection timed out for private@example.test', new Request('GET', 'https://mautic.example.test')),
        ReadFailureKind::Timeout,
    ],
]);

it('rejects unexpected successful response shapes', function () {
    useContactReadResponses([
        new Response(200, ['Content-Type' => 'application/json'], '{"contact":{"id":"not-an-integer"}}'),
    ]);

    expect(fn () => app(MauticManager::class)->connection()->contactReads()->find(42))
        ->toThrow(fn (MauticReadException $exception) => $exception->kind === ReadFailureKind::UnexpectedResponse);
});
