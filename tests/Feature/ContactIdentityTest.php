<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mautic\Auth\BasicAuth;
use Trianity\LaravelMautic\Contracts\ContactMappingRepository;
use Trianity\LaravelMautic\Exceptions\ContactMappingChanged;
use Trianity\LaravelMautic\Exceptions\ContactMappingConflict;
use Trianity\LaravelMautic\Identity\ContactDiscoveryOutcome;
use Trianity\LaravelMautic\Identity\DatabaseContactMappingRepository;
use Trianity\LaravelMautic\Identity\LocalContactReference;
use Trianity\LaravelMautic\Identity\RemoteContactState;
use Trianity\LaravelMautic\MauticConnection;

beforeEach(function () {
    config()->set('database.default', 'identity-test');
    config()->set('database.connections.identity-test', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    DB::purge('identity-test');

    Schema::connection('identity-test')->create('mautic_contact_mappings', function (Blueprint $table): void {
        $table->id();
        $table->string('connection', 191);
        $table->string('local_scope', 191);
        $table->string('local_key', 191);
        $table->unsignedBigInteger('remote_id');
        $table->string('remote_state', 16);
        $table->unsignedBigInteger('merged_into_remote_id')->nullable();
        $table->unsignedBigInteger('generation');
        $table->timestamps();
        $table->unique(['connection', 'local_scope', 'local_key']);
        $table->unique(['connection', 'remote_id']);
    });
});

it('maps an opaque host Contact reference without a User or guard dependency and isolates connections', function () {
    $hostContact = new class('tenant:acme:crm-contact', 'contact-01', 'before@example.test')
    {
        public function __construct(
            public string $scope,
            public string $key,
            public string $email,
        ) {}

        public function reference(): LocalContactReference
        {
            return new LocalContactReference($this->scope, $this->key);
        }
    };
    $repository = new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'identity-test');

    $main = $repository->claim('main', $hostContact->reference(), 42);
    $secondary = $repository->claim('secondary', $hostContact->reference(), 77);
    $hostContact->email = 'after@example.test';

    expect($repository->find('main', $hostContact->reference()))->toEqual($main)
        ->and($repository->find('secondary', $hostContact->reference()))->toEqual($secondary)
        ->and($main->remoteId)->toBe(42)
        ->and($secondary->remoteId)->toBe(77)
        ->and($repository->claim('main', $hostContact->reference(), 42))->toEqual($main)
        ->and(DB::connection('identity-test')->table('mautic_contact_mappings')->count())->toBe(2);
});

it('binds replaceable Laravel persistence and publishes its migration explicitly', function () {
    config()->set('mautic.identity.database_connection', 'identity-test');

    expect(app(ContactMappingRepository::class))->toBeInstanceOf(DatabaseContactMappingRepository::class);

    $this->artisan('vendor:publish', ['--tag' => 'mautic-identity-migrations', '--force' => true])
        ->assertSuccessful();

    expect(is_file(database_path('migrations/2026_10_19_010000_create_mautic_contact_mappings.php')))
        ->toBeTrue();
});

it('rejects crossed identity claims and supports explicit versioned recovery', function () {
    $repository = new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'identity-test');
    $ada = new LocalContactReference('tenant:acme:crm-contact', 'ada');
    $grace = new LocalContactReference('tenant:acme:crm-contact', 'grace');
    $mapping = $repository->claim('main', $ada, 42);

    expect(fn () => $repository->claim('main', $ada, 43))->toThrow(ContactMappingConflict::class)
        ->and(fn () => $repository->claim('main', $grace, 42))->toThrow(ContactMappingConflict::class);

    $missing = $repository->markRemoteState($mapping, RemoteContactState::Missing);
    expect($missing->remoteState)->toBe(RemoteContactState::Missing)
        ->and($missing->generation)->toBe(2)
        ->and(fn () => $repository->markRemoteState($mapping, RemoteContactState::Deleted))
        ->toThrow(ContactMappingChanged::class);

    $recovered = $repository->relink($missing, 84);
    expect($recovered->remoteId)->toBe(84)
        ->and($recovered->remoteState)->toBe(RemoteContactState::Active)
        ->and($recovered->generation)->toBe(3)
        ->and($repository->findByRemoteId('main', 42))->toBeNull()
        ->and($repository->findByRemoteId('main', 84))->toEqual($recovered);
});

it('records merged identity for explicit review without automatically relinking', function () {
    $repository = new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'identity-test');
    $mapping = $repository->claim(
        'main',
        new LocalContactReference('tenant:acme:crm-contact', 'merged-contact'),
        42,
    );

    $merged = $repository->markRemoteState($mapping, RemoteContactState::Merged, 84);

    expect($merged->remoteId)->toBe(42)
        ->and($merged->mergedIntoRemoteId)->toBe(84)
        ->and($repository->findByRemoteId('main', 84))->toBeNull();
});

it('returns zero one and multiple exact email discoveries without claiming identity', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], '{"total":0,"contacts":[]}'),
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'total' => 1,
            'contacts' => [
                ['id' => 42, 'fields' => ['all' => ['email' => ' Person@example.test ']]],
            ],
        ], JSON_THROW_ON_ERROR)),
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'total' => 2,
            'contacts' => [
                ['id' => 42, 'fields' => ['all' => ['email' => 'person@example.test']]],
                ['id' => 84, 'fields' => ['all' => ['email' => 'PERSON@example.test']]],
            ],
        ], JSON_THROW_ON_ERROR)),
    ]));
    $stack->push(Middleware::history($history));
    $client = new Client(['handler' => $stack]);
    $auth = new BasicAuth($client);
    $auth->setup('user', 'password');
    $connection = new MauticConnection('main', 'https://mautic.example.test/api/', $auth);

    $none = $connection->contactDiscovery()->exactEmail('nobody@example.test');
    $unique = $connection->contactDiscovery()->exactEmail('person@example.test');
    $ambiguous = $connection->contactDiscovery()->exactEmail('person@example.test');

    expect($none->outcome)->toBe(ContactDiscoveryOutcome::None)
        ->and($none->contacts)->toBe([])
        ->and($unique->outcome)->toBe(ContactDiscoveryOutcome::Unique)
        ->and($unique->uniqueContact()?->remoteId)->toBe(42)
        ->and($ambiguous->outcome)->toBe(ContactDiscoveryOutcome::Ambiguous)
        ->and(array_map(fn ($contact) => $contact->remoteId, $ambiguous->contacts))->toBe([42, 84])
        ->and(DB::connection('identity-test')->table('mautic_contact_mappings')->count())->toBe(0);

    foreach ($history as $transaction) {
        parse_str($transaction['request']->getUri()->getQuery(), $query);
        expect($query['where'][0] ?? null)->toMatchArray([
            'col' => 'email',
            'expr' => 'eq',
        ])
            ->and($query['where'][0]['val'] ?? null)->toBeString();
    }
});
