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
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Contracts\HttpClientFactory;
use Trianity\LaravelMautic\Data\ValuePresence;
use Trianity\LaravelMautic\Exceptions\AmbiguousMutationOutcome;
use Trianity\LaravelMautic\Exceptions\InvalidWriteRequest;
use Trianity\LaravelMautic\Exceptions\MauticWriteException;
use Trianity\LaravelMautic\Exceptions\RestrictionStateNotVerified;
use Trianity\LaravelMautic\MauticManager;
use Trianity\LaravelMautic\Restriction\ManualEmailBlock;
use Trianity\LaravelMautic\Restriction\RestrictionMutationOutcome;
use Trianity\LaravelMautic\Write\FailureKind;

function useRestrictionWriteResponses(array $responses, array &$history = []): void
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

/** @param list<mixed>|null $dnc */
function restrictionContactResponse(?array $dnc, bool $includeDnc = true, array $extra = []): string
{
    $contact = ['id' => 42, 'fields' => ['all' => ['email' => 'person@example.test']]];
    if ($includeDnc) {
        $contact['doNotContact'] = $dnc;
    }

    return json_encode($extra + ['contact' => $contact], JSON_THROW_ON_ERROR);
}

/** @return array<string, mixed> */
function restrictionEntry(int|string $reason, string $comments = 'existing'): array
{
    return [
        'id' => 91,
        'channel' => 'email',
        'reason' => $reason,
        'channelId' => null,
        'comments' => $comments,
    ];
}

function fixedRestrictionClock(): Clock
{
    return new class implements Clock
    {
        public function now(): int
        {
            return 1792769400;
        }
    };
}

it('posts a stable manual email block through the SDK and verifies the returned state', function () {
    $history = [];
    $command = ManualEmailBlock::record('Operator requested suppression.', fixedRestrictionClock());
    $comments = "Operator requested suppression.\n\n[trianity/laravel-mautic; recorded_at=2026-10-23T15:30:00Z]";
    useRestrictionWriteResponses([
        new Response(200, ['Content-Type' => 'application/json'], restrictionContactResponse([
            restrictionEntry(3, $comments),
        ])),
        new Response(200, ['Content-Type' => 'application/json'], restrictionContactResponse([
            restrictionEntry(3, $comments),
        ])),
    ], $history);

    $writer = app(MauticManager::class)->connection()->restrictionWrites();
    $first = $writer->blockEmail(42, $command);
    $second = $writer->blockEmail(42, $command);

    expect($command->recordedAt)->toBe('2026-10-23T15:30:00Z')
        ->and($command->comments())->toBe($comments)
        ->and($first->outcome)->toBe(RestrictionMutationOutcome::ManualPresent)
        ->and($first->submittedCommentsObserved)->toBeTrue()
        ->and($first->recordFound)->toBeNull()
        ->and($first->emailRestrictions)->toHaveCount(1)
        ->and($first->emailRestrictions[0]->dateAdded->presence)->toBe(ValuePresence::Missing)
        ->and($second->outcome)->toBe(RestrictionMutationOutcome::ManualPresent)
        ->and($history)->toHaveCount(2);

    foreach ($history as $transaction) {
        expect($transaction['request']->getMethod())->toBe('POST')
            ->and((string) $transaction['request']->getUri())
            ->toBe('https://mautic.example.test/api/contacts/42/dnc/email/add')
            ->and(json_decode((string) $transaction['request']->getBody(), true, flags: JSON_THROW_ON_ERROR))
            ->toBe([
                'reason' => 3,
                'channelId' => null,
                'comments' => $comments,
            ]);
    }
});

it('reports a retained unsubscribe instead of claiming a manual block was stored', function () {
    useRestrictionWriteResponses([
        new Response(200, ['Content-Type' => 'application/json'], restrictionContactResponse([
            restrictionEntry(1, 'subscriber choice'),
        ])),
    ]);

    $result = app(MauticManager::class)->connection()->restrictionWrites()->blockEmail(
        42,
        ManualEmailBlock::record('Administrative request', fixedRestrictionClock()),
    );

    expect($result->outcome)->toBe(RestrictionMutationOutcome::ExistingRestrictionRetained)
        ->and($result->submittedCommentsObserved)->toBeFalse()
        ->and($result->emailRestrictions[0]->knownReason()?->value)->toBe(1);
});

it('preserves an unknown reason and missing date metadata in a retained result', function () {
    useRestrictionWriteResponses([
        new Response(200, ['Content-Type' => 'application/json'], restrictionContactResponse([
            restrictionEntry('91', 'unknown state'),
        ])),
    ]);

    $result = app(MauticManager::class)->connection()->restrictionWrites()->blockEmail(
        42,
        ManualEmailBlock::record('Administrative request', fixedRestrictionClock()),
    );

    expect($result->outcome)->toBe(RestrictionMutationOutcome::ExistingRestrictionRetained)
        ->and($result->emailRestrictions[0]->reason->value())->toBe('91')
        ->and($result->emailRestrictions[0]->knownReason())->toBeNull()
        ->and($result->emailRestrictions[0]->dateAdded->presence)->toBe(ValuePresence::Missing);
});

it('returns verified state instead of assuming an accepted block changed the contact', function () {
    useRestrictionWriteResponses([
        new Response(200, ['Content-Type' => 'application/json'], restrictionContactResponse([])),
    ]);

    $result = app(MauticManager::class)->connection()->restrictionWrites()->blockEmail(
        42,
        ManualEmailBlock::record('Administrative request', fixedRestrictionClock()),
    );

    expect($result->outcome)->toBe(RestrictionMutationOutcome::NoRestrictionPresent)
        ->and($result->emailRestrictions)->toBe([])
        ->and($result->submittedCommentsObserved)->toBeFalse();
});

it('performs one explicit read when the mutation embeds an unusable contact shape', function () {
    $history = [];
    $command = ManualEmailBlock::record('Administrative request', fixedRestrictionClock());
    useRestrictionWriteResponses([
        new Response(200, ['Content-Type' => 'application/json'], restrictionContactResponse([
            restrictionEntry(3, $command->comments()) + ['dateAdded' => ['malformed']],
        ])),
        new Response(200, ['Content-Type' => 'application/json'], restrictionContactResponse([
            restrictionEntry(3, $command->comments()),
        ])),
    ], $history);

    $result = app(MauticManager::class)->connection()->restrictionWrites()->blockEmail(42, $command);

    expect($result->outcome)->toBe(RestrictionMutationOutcome::ManualPresent)
        ->and($result->submittedCommentsObserved)->toBeTrue()
        ->and($history)->toHaveCount(2)
        ->and($history[0]['request']->getMethod())->toBe('POST')
        ->and((string) $history[0]['request']->getUri())
        ->toBe('https://mautic.example.test/api/contacts/42/dnc/email/add')
        ->and($history[1]['request']->getMethod())->toBe('GET')
        ->and((string) $history[1]['request']->getUri())
        ->toBe('https://mautic.example.test/api/contacts/42');
});

it('posts a channel-level release and distinguishes applied from no matching restriction', function () {
    $history = [];
    useRestrictionWriteResponses([
        new Response(200, ['Content-Type' => 'application/json'], restrictionContactResponse([], extra: [
            'recordFound' => true,
        ])),
        new Response(200, ['Content-Type' => 'application/json'], restrictionContactResponse([], extra: [
            'recordFound' => false,
        ])),
    ], $history);

    $writer = app(MauticManager::class)->connection()->restrictionWrites();
    $released = $writer->releaseEmailRestriction(42);
    $absent = $writer->releaseEmailRestriction(42);

    expect($released->outcome)->toBe(RestrictionMutationOutcome::Released)
        ->and($released->recordFound)->toBeTrue()
        ->and($absent->outcome)->toBe(RestrictionMutationOutcome::NoMatchingRestriction)
        ->and($absent->recordFound)->toBeFalse()
        ->and($history)->toHaveCount(2);

    foreach ($history as $transaction) {
        expect($transaction['request']->getMethod())->toBe('POST')
            ->and((string) $transaction['request']->getUri())
            ->toBe('https://mautic.example.test/api/contacts/42/dnc/email/remove')
            ->and((string) $transaction['request']->getBody())->toBe('[]');
    }
});

it('reports that another email restriction remains after a successful channel release', function () {
    useRestrictionWriteResponses([
        new Response(200, ['Content-Type' => 'application/json'], restrictionContactResponse([
            restrictionEntry(2, 'another row'),
        ], extra: ['recordFound' => true])),
    ]);

    $result = app(MauticManager::class)->connection()->restrictionWrites()->releaseEmailRestriction(42);

    expect($result->outcome)->toBe(RestrictionMutationOutcome::RestrictionRemains)
        ->and($result->recordFound)->toBeTrue()
        ->and($result->emailRestrictions)->toHaveCount(1);
});

it('rejects invalid restriction commands before networking', function () {
    $history = [];
    useRestrictionWriteResponses([], $history);
    $writer = app(MauticManager::class)->connection()->restrictionWrites();

    expect(fn () => ManualEmailBlock::record('   ', fixedRestrictionClock()))
        ->toThrow(InvalidWriteRequest::class)
        ->and(fn () => $writer->blockEmail(0, ManualEmailBlock::record('Reason', fixedRestrictionClock())))
        ->toThrow(InvalidWriteRequest::class)
        ->and(fn () => $writer->releaseEmailRestriction(-1))
        ->toThrow(InvalidWriteRequest::class)
        ->and($history)->toBe([]);
});

it('distinguishes unavailable or malformed post-mutation state from a proved rejection', function (string $response) {
    useRestrictionWriteResponses([
        new Response(200, ['Content-Type' => 'application/json'], $response),
        new Response(200, ['Content-Type' => 'application/json'], $response),
    ]);

    expect(fn () => app(MauticManager::class)->connection()->restrictionWrites()->blockEmail(
        42,
        ManualEmailBlock::record('Administrative request', fixedRestrictionClock()),
    ))->toThrow(fn (RestrictionStateNotVerified $exception) => $exception->mutationMayHaveCompleted);
})->with([
    'missing DNC' => [restrictionContactResponse(null, includeDnc: false)],
    'null DNC' => [restrictionContactResponse(null)],
    'malformed DNC' => [restrictionContactResponse(['not-an-entry'])],
]);

it('normalizes restriction authentication permission and validation failures', function (int $status, FailureKind $kind) {
    useRestrictionWriteResponses([
        new Response($status, ['Content-Type' => 'application/json'], json_encode([
            'errors' => [['code' => $status, 'message' => 'private remote detail']],
        ], JSON_THROW_ON_ERROR)),
    ]);

    expect(fn () => app(MauticManager::class)->connection()->restrictionWrites()->blockEmail(
        42,
        ManualEmailBlock::record('Administrative request', fixedRestrictionClock()),
    ))->toThrow(fn (MauticWriteException $exception) => $exception->kind === $kind
        && ! str_contains($exception->getMessage(), 'private remote detail'));
})->with([
    'authentication' => [401, FailureKind::Authentication],
    'permission' => [403, FailureKind::Authentication],
    'validation' => [422, FailureKind::Validation],
]);

it('surfaces an ambiguous restriction transport outcome without replay', function () {
    $history = [];
    useRestrictionWriteResponses([
        new ConnectException(
            'Connection lost after send',
            new Request('POST', 'https://mautic.example.test/api/contacts/42/dnc/email/add'),
        ),
    ], $history);

    expect(fn () => app(MauticManager::class)->connection()->restrictionWrites()->blockEmail(
        42,
        ManualEmailBlock::record('Administrative request', fixedRestrictionClock()),
    ))->toThrow(AmbiguousMutationOutcome::class)
        ->and($history)->toHaveCount(1);
});
