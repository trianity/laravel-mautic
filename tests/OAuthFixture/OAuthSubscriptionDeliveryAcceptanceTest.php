<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Contracts\ContactSyncTransport;
use Trianity\LaravelMautic\Contracts\StaticSegmentGateway;
use Trianity\LaravelMautic\Contracts\SubscriptionTransport;
use Trianity\LaravelMautic\Data\DoNotContactEntry;
use Trianity\LaravelMautic\Data\DoNotContactReason;
use Trianity\LaravelMautic\Data\SegmentMemberships;
use Trianity\LaravelMautic\Data\ValuePresence;
use Trianity\LaravelMautic\Exceptions\ContactNotFound;
use Trianity\LaravelMautic\Exceptions\SegmentNotFound;
use Trianity\LaravelMautic\Identity\DatabaseContactMappingRepository;
use Trianity\LaravelMautic\Identity\LocalContactReference;
use Trianity\LaravelMautic\MauticConnection;
use Trianity\LaravelMautic\Outbox\ContactOutbox;
use Trianity\LaravelMautic\Outbox\ContactSyncCommand;
use Trianity\LaravelMautic\Outbox\DatabaseOutboxStore;
use Trianity\LaravelMautic\Outbox\OutboxDispatcher;
use Trianity\LaravelMautic\Outbox\OutboxProcessor;
use Trianity\LaravelMautic\Restriction\ManualEmailBlock;
use Trianity\LaravelMautic\Segment\StaticSegmentDefinition;
use Trianity\LaravelMautic\Subscription\DatabaseSubscriptionStore;
use Trianity\LaravelMautic\Subscription\SegmentTarget;
use Trianity\LaravelMautic\Subscription\SubscriptionCommand;
use Trianity\LaravelMautic\Subscription\SubscriptionDelivery;
use Trianity\LaravelMautic\Subscription\SubscriptionDispatcher;
use Trianity\LaravelMautic\Subscription\SubscriptionProcessor;
use Trianity\LaravelMautic\Subscription\SubscriptionStatus;
use Trianity\LaravelMautic\Tests\Support\OAuthAcceptanceHarness;
use Trianity\LaravelMautic\Tests\Support\OAuthAcceptanceRequestBudget;
use Trianity\LaravelMautic\Tests\Support\OAuthFixtureConfiguration;
use Trianity\LaravelMautic\Tests\Support\OAuthStaticSegmentPreflight;
use Trianity\LaravelMautic\Tests\Support\OAuthSubscriptionEvidence;
use Trianity\LaravelMautic\Write\ContactFields;

it('verifies durable subscription delivery through OAuth', function () {
    if (getenv('MAUTIC_OAUTH_SUBSCRIPTION_TESTS') !== '1') {
        test()->markTestSkipped('Set MAUTIC_OAUTH_SUBSCRIPTION_TESTS=1 for durable subscription delivery acceptance.');
    }
    $configuration = OAuthFixtureConfiguration::staticSegmentFromEnvironment();
    $preparedSegmentId = $configuration->staticSegmentId
        ?? throw new RuntimeException('The prepared static segment ID is required.');
    $budget = new OAuthAcceptanceRequestBudget(operationLimit: 34, cleanupLimit: 10);
    $harness = new OAuthAcceptanceHarness($configuration->oauth, requestBudget: $budget);
    $connection = $harness->connection();
    (new OAuthStaticSegmentPreflight)->validate($connection->api('segments')->get($preparedSegmentId), $preparedSegmentId);

    $runId = bin2hex(random_bytes(12));
    $evidence = new OAuthSubscriptionEvidence($runId);
    $email = 'lm-subscription-'.$runId.'@example.test';
    $createdAlias = 'lm-subscription-'.$runId;
    $createdName = 'Laravel Mautic subscription '.$runId;
    $databasePath = sys_get_temp_dir().'/laravel-mautic-subscription-'.$runId.'.sqlite';
    touch($databasePath);
    chmod($databasePath, 0600);
    config()->set('database.connections.oauth-subscription', ['driver' => 'sqlite', 'database' => $databasePath, 'prefix' => '']);
    foreach (['identity', 'outbox', 'subscription'] as $section) {
        config()->set('mautic.'.$section.'.database_connection', 'oauth-subscription');
    }
    DB::purge('oauth-subscription');
    (require dirname(__DIR__, 2).'/database/migrations/create_mautic_contact_mappings.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/create_mautic_contact_outbox.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/create_mautic_subscription_delivery.php.stub')->up();

    Queue::fake();
    $clock = app(Clock::class);
    $mappings = new DatabaseContactMappingRepository(DB::getFacadeRoot(), 'oauth-subscription');
    $outboxStore = new DatabaseOutboxStore(DB::getFacadeRoot(), $clock, 'oauth-subscription');
    $subscriptionStore = new DatabaseSubscriptionStore(DB::getFacadeRoot(), $clock, 'oauth-subscription');
    $contactOutbox = new ContactOutbox($outboxStore, new OutboxDispatcher(Queue::getFacadeRoot(), $outboxStore));
    $delivery = new SubscriptionDelivery(
        $subscriptionStore,
        $contactOutbox,
        $mappings,
        new SubscriptionDispatcher(Queue::getFacadeRoot()),
    );
    $contactTransport = new class($connection) implements ContactSyncTransport
    {
        public function __construct(private readonly MauticConnection $connection) {}

        public function create(string $connection, ContactFields $fields): int
        {
            return $this->connection->contactWrites()->create($fields)->remoteId;
        }

        public function update(string $connection, int $remoteId, ContactFields $fields): void
        {
            $this->connection->contactWrites()->update($remoteId, $fields);
        }
    };
    $subscriptionTransport = new class($connection) implements SubscriptionTransport
    {
        public function __construct(private readonly MauticConnection $connection) {}

        public function segments(string $connection): StaticSegmentGateway
        {
            return $this->connection->staticSegments();
        }

        public function memberships(string $connection, int $contactId): SegmentMemberships
        {
            return $this->connection->contactReads()->segments($contactId);
        }

        public function addMembership(string $connection, int $contactId, int $segmentId): void
        {
            $this->connection->contactWrites()->addToSegment($contactId, $segmentId);
        }
    };
    $outboxProcessor = new OutboxProcessor($outboxStore, $contactTransport, $mappings, $clock);
    $subscriptionProcessor = new SubscriptionProcessor($subscriptionStore, $outboxStore, $mappings, $subscriptionTransport);
    $local = new LocalContactReference('oauth-subscription-fixture', $runId);
    $remoteContactId = null;
    $createdSegmentId = null;
    $initialRestriction = null;
    $failure = null;

    try {
        $bootstrap = ContactSyncCommand::create(
            'subscription-bootstrap-'.$runId,
            'oauth-acceptance',
            $local,
            1,
            ContactFields::fromArray(['email' => $email, 'firstname' => 'RestrictionFixture'], ['email', 'firstname']),
            'oauth-subscription-fixture',
        );
        DB::connection('oauth-subscription')->transaction(fn () => $contactOutbox->record($bootstrap));
        $outboxProcessor->process($bootstrap->operationId);
        $mapping = $mappings->find('oauth-acceptance', $local)
            ?? throw new RuntimeException('The live contact mapping was not created by the public contact operation.');
        $remoteContactId = $mapping->remoteId;
        $connection->restrictionWrites()->blockEmail(
            $remoteContactId,
            ManualEmailBlock::record('Subscription DNC preservation '.$runId, $clock),
        );
        $restrictedContact = $connection->contactReads()->find($remoteContactId);
        if ($restrictedContact->doNotContact->presence !== ValuePresence::Value) {
            throw new RuntimeException('The disposable contact does not expose its manual restriction.');
        }
        $manualEntries = array_values(array_filter(
            $restrictedContact->doNotContact->value(),
            static fn (DoNotContactEntry $entry): bool => $entry->channel === 'email'
                && $entry->knownReason() === DoNotContactReason::Manual,
        ));
        if (count($manualEntries) !== 1) {
            throw new RuntimeException('The disposable contact requires one exact manual email restriction.');
        }
        $initialRestriction = $manualEntries[0];

        $firstCommand = new SubscriptionCommand(
            'oauth-acceptance', $local, 'prepared-'.$runId, 2,
            ContactFields::fromArray(['firstname' => 'SubscriptionOne'], ['firstname']),
            SegmentTarget::existing('prepared-'.$preparedSegmentId, $preparedSegmentId),
        );
        $first = DB::connection('oauth-subscription')->transaction(fn () => $delivery->record($firstCommand));
        $outboxProcessor->process($first->contactOperationId);
        $subscriptionProcessor->process($first->operationId);
        expect($subscriptionStore->get($first->operationId)?->status)->toBe(SubscriptionStatus::Completed);

        $replay = DB::connection('oauth-subscription')->transaction(fn () => $delivery->record($firstCommand));
        expect($replay->operationId)->toBe($first->operationId);

        $createdCommand = new SubscriptionCommand(
            'oauth-acceptance', $local, 'created-'.$runId, 3,
            ContactFields::fromArray(['firstname' => 'SubscriptionTwo'], ['firstname']),
            SegmentTarget::createIfAbsent('created-'.$runId, new StaticSegmentDefinition($createdName, $createdAlias)),
        );
        $created = DB::connection('oauth-subscription')->transaction(fn () => $delivery->record($createdCommand));
        $outboxProcessor->process($created->contactOperationId);
        $subscriptionProcessor->process($created->operationId);
        $createdRecord = $subscriptionStore->get($created->operationId);
        $createdSegmentId = $createdRecord?->remoteSegmentId;
        $finalContact = $connection->contactReads()->find($remoteContactId);
        $finalRestrictions = $finalContact->doNotContact->presence === ValuePresence::Value
            ? array_values(array_filter(
                $finalContact->doNotContact->value(),
                static fn (DoNotContactEntry $entry): bool => $entry->channel === 'email'
                    && $entry->knownReason() === DoNotContactReason::Manual,
            )) : [];
        expect($createdRecord?->status)->toBe(SubscriptionStatus::Completed)
            ->and($createdSegmentId)->toBeInt()
            ->and($finalContact->field('firstname')->value())->toBe('SubscriptionTwo')
            ->and($finalRestrictions)->toHaveCount(1)
            ->and($finalRestrictions[0])->toEqual($initialRestriction);
        $evidence->completed();
    } catch (Throwable $exception) {
        $failure = $exception;
    }

    $budget->beginCleanup();
    $cleanupFailure = null;
    try {
        if (is_int($remoteContactId)) {
            $stored = $connection->contactReads()->find($remoteContactId);
            expect($stored->field('email')->value())->toBe($email);
            $connection->contacts()->delete($remoteContactId);
            expect(fn () => $connection->contactReads()->find($remoteContactId))->toThrow(ContactNotFound::class);
        }
        if (is_int($createdSegmentId)) {
            $segment = $connection->staticSegments()->find($createdSegmentId);
            expect($segment->name)->toBe($createdName)->and($segment->alias)->toBe($createdAlias);
            $connection->api('segments')->delete($createdSegmentId);
            expect(fn () => $connection->staticSegments()->find($createdSegmentId))->toThrow(SegmentNotFound::class);
        }
    } catch (Throwable $exception) {
        $cleanupFailure = $exception;
        $evidence->cleanup('failed');
    } finally {
        DB::purge('oauth-subscription');
        if (is_file($databasePath)) {
            unlink($databasePath);
        }
    }
    if ($cleanupFailure === null) {
        $evidence->cleanup('deleted');
    }
    $evidence->requestCounts($budget->operationAttempts(), $budget->cleanupAttempts());
    expect($budget->operationAttempts())->toBeLessThanOrEqual(34)
        ->and($budget->cleanupAttempts())->toBeLessThanOrEqual(10);

    if ($failure !== null) {
        throw $failure;
    }
    if ($cleanupFailure !== null) {
        throw $cleanupFailure;
    }
});
