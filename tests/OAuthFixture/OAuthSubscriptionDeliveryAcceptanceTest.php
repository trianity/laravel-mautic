<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Contracts\ContactSyncTransport;
use Trianity\LaravelMautic\Contracts\MembershipPlanTransport;
use Trianity\LaravelMautic\Contracts\StaticSegmentGateway;
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
use Trianity\LaravelMautic\Subscription\MembershipPlan;
use Trianity\LaravelMautic\Subscription\MembershipPlanCommand;
use Trianity\LaravelMautic\Subscription\MembershipPresence;
use Trianity\LaravelMautic\Subscription\MembershipRequirement;
use Trianity\LaravelMautic\Subscription\SegmentTarget;
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
    $budget = new OAuthAcceptanceRequestBudget(operationLimit: 42, cleanupLimit: 16);
    $harness = new OAuthAcceptanceHarness($configuration->oauth, requestBudget: $budget);
    $connection = $harness->connection();
    (new OAuthStaticSegmentPreflight)->validate($connection->api('segments')->get($preparedSegmentId), $preparedSegmentId);

    $runId = bin2hex(random_bytes(12));
    $evidence = new OAuthSubscriptionEvidence($runId);
    $email = 'lm-subscription-'.$runId.'@example.test';
    $removeAlias = 'lm-subscription-remove-'.$runId;
    $removeName = 'Laravel Mautic subscription remove '.$runId;
    $addAlias = 'lm-subscription-add-'.$runId;
    $addName = 'Laravel Mautic subscription add '.$runId;
    $untouchedAlias = 'lm-subscription-untouched-'.$runId;
    $untouchedName = 'Laravel Mautic subscription untouched '.$runId;
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
    (require dirname(__DIR__, 2).'/database/migrations/add_mautic_subscription_membership_plans.php.stub')->up();

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
    $subscriptionTransport = new class($connection) implements MembershipPlanTransport
    {
        /** @var list<string> */
        public array $membershipMutations = [];

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
            $this->membershipMutations[] = 'add';
            $this->connection->contactWrites()->addToSegment($contactId, $segmentId);
        }

        public function removeMembership(string $connection, int $contactId, int $segmentId): void
        {
            $this->membershipMutations[] = 'remove';
            $this->connection->contactWrites()->removeFromSegment($contactId, $segmentId);
        }
    };
    $outboxProcessor = new OutboxProcessor($outboxStore, $contactTransport, $mappings, $clock);
    $subscriptionProcessor = new SubscriptionProcessor($subscriptionStore, $outboxStore, $mappings, $subscriptionTransport);
    $local = new LocalContactReference('oauth-subscription-fixture', $runId);
    $remoteContactId = null;
    $removeSegmentId = null;
    $addSegmentId = null;
    $untouchedSegmentId = null;
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
        $removeSegmentId = $connection->staticSegments()->create(
            new StaticSegmentDefinition($removeName, $removeAlias),
        )->remoteId;
        $addSegmentId = $connection->staticSegments()->create(
            new StaticSegmentDefinition($addName, $addAlias),
        )->remoteId;
        $untouchedSegmentId = $connection->staticSegments()->create(
            new StaticSegmentDefinition($untouchedName, $untouchedAlias),
        )->remoteId;
        $connection->contactWrites()->addToSegment($remoteContactId, $removeSegmentId);
        $connection->contactWrites()->addToSegment($remoteContactId, $untouchedSegmentId);
        $baselineMembershipIds = array_map(
            static fn ($membership): int => $membership->remoteId,
            $connection->contactReads()->segments($remoteContactId)->memberships,
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
        expect($baselineMembershipIds)->toContain($removeSegmentId)
            ->and($baselineMembershipIds)->toContain($untouchedSegmentId)
            ->and($baselineMembershipIds)->not->toContain($addSegmentId);

        $createdCommand = new MembershipPlanCommand(
            'oauth-acceptance', $local, 'mixed-'.$runId, 2,
            ContactFields::fromArray(['firstname' => 'SubscriptionTwo'], ['firstname']),
            new MembershipPlan([
                new MembershipRequirement(
                    SegmentTarget::existing('remove-'.$runId, $removeSegmentId),
                    MembershipPresence::Absent,
                ),
                new MembershipRequirement(
                    SegmentTarget::existing('add-'.$runId, $addSegmentId),
                    MembershipPresence::Present,
                ),
            ]),
        );
        $created = DB::connection('oauth-subscription')->transaction(fn () => $delivery->recordPlan($createdCommand));
        $outboxProcessor->process($created->contactOperationId);
        $subscriptionProcessor->process($created->operationId);
        $createdRecord = $subscriptionStore->get($created->operationId);
        $finalMembershipIds = array_map(
            static fn ($membership): int => $membership->remoteId,
            $connection->contactReads()->segments($remoteContactId)->memberships,
        );
        $finalContact = $connection->contactReads()->find($remoteContactId);
        $finalRestrictions = $finalContact->doNotContact->presence === ValuePresence::Value
            ? array_values(array_filter(
                $finalContact->doNotContact->value(),
                static fn (DoNotContactEntry $entry): bool => $entry->channel === 'email'
                    && $entry->knownReason() === DoNotContactReason::Manual,
            )) : [];
        expect($createdRecord?->status)->toBe(SubscriptionStatus::Completed)
            ->and($finalMembershipIds)->toContain($addSegmentId)
            ->and($finalMembershipIds)->not->toContain($removeSegmentId)
            ->and($finalMembershipIds)->toContain($untouchedSegmentId)
            ->and($finalContact->field('firstname')->value())->toBe('SubscriptionTwo')
            ->and($finalRestrictions)->toHaveCount(1)
            ->and($finalRestrictions[0])->toEqual($initialRestriction)
            ->and($subscriptionTransport->membershipMutations)->toBe(['remove', 'add']);

        $mutationCount = count($subscriptionTransport->membershipMutations);
        $replay = DB::connection('oauth-subscription')->transaction(fn () => $delivery->recordPlan($createdCommand));
        $subscriptionProcessor->process($replay->operationId);
        expect($replay->operationId)->toBe($created->operationId)
            ->and($subscriptionStore->get($replay->operationId)?->status)->toBe(SubscriptionStatus::Completed)
            ->and($subscriptionTransport->membershipMutations)->toHaveCount($mutationCount)
            ->and(DB::connection('oauth-subscription')->table('mautic_subscription_intents')->count())->toBe(1)
            ->and(DB::connection('oauth-subscription')->table('mautic_subscription_plan_items')->count())->toBe(2);
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
        foreach ([
            [$removeSegmentId, $removeName, $removeAlias],
            [$addSegmentId, $addName, $addAlias],
            [$untouchedSegmentId, $untouchedName, $untouchedAlias],
        ] as [$segmentId, $segmentName, $segmentAlias]) {
            if (! is_int($segmentId)) {
                continue;
            }
            $segment = $connection->staticSegments()->find($segmentId);
            expect($segment->name)->toBe($segmentName)->and($segment->alias)->toBe($segmentAlias);
            $connection->api('segments')->delete($segmentId);
            expect(fn () => $connection->staticSegments()->find($segmentId))->toThrow(SegmentNotFound::class);
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
    expect($budget->operationAttempts())->toBeLessThanOrEqual(42)
        ->and($budget->cleanupAttempts())->toBeLessThanOrEqual(16);

    if ($failure !== null) {
        throw $failure;
    }
    if ($cleanupFailure !== null) {
        throw $cleanupFailure;
    }
});
