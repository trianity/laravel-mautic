<?php

declare(strict_types=1);

use Trianity\LaravelMautic\Contracts\ContactWriter;
use Trianity\LaravelMautic\Data\Contact;
use Trianity\LaravelMautic\Data\OptionalValue;
use Trianity\LaravelMautic\Exceptions\ContactAlreadyExists;
use Trianity\LaravelMautic\Exceptions\ContactUpdateTargetNotFound;
use Trianity\LaravelMautic\Fakes\FakeContactWriter;
use Trianity\LaravelMautic\Write\BatchItemOutcome;
use Trianity\LaravelMautic\Write\ContactFields;
use Trianity\LaravelMautic\Write\ContactUpdate;
use Trianity\LaravelMautic\Write\FailureKind;

it('provides deterministic writes and manual segment commands through the public contract', function () {
    $fake = FakeContactWriter::make(startingRemoteId: 100);
    $fields = ContactFields::fromArray(['email' => 'person@example.test'], ['email']);
    $created = $fake->create($fields);
    $updated = $fake->update(100, ContactFields::fromArray(['firstname' => 'Ada'], ['firstname']));
    $fake->addToSegment(100, 9);
    $fake->removeFromSegment(100, 9);

    expect($fake)->toBeInstanceOf(ContactWriter::class)
        ->and($created->remoteId)->toBe(100)
        ->and($updated->field('email')->value())->toBe('person@example.test')
        ->and($updated->field('firstname')->value())->toBe('Ada')
        ->and($fake->segmentIdsFor(100))->toBe([]);
});

it('rejects duplicate emails inside one creation batch', function () {
    $fake = FakeContactWriter::make();
    $first = ContactFields::fromArray(['email' => 'Same@example.test'], ['email']);
    $second = ContactFields::fromArray(['email' => 'same@example.test'], ['email']);

    expect(fn () => $fake->createBatch($first, $second))
        ->toThrow(ContactAlreadyExists::class)
        ->and($fake->contacts())->toBe([]);
});

it('never creates when an update target is missing', function () {
    $fake = FakeContactWriter::make()->withContacts(new Contact(1, OptionalValue::present([])));

    expect(fn () => $fake->update(404, ContactFields::fromArray(['firstname' => 'Ada'], ['firstname'])))
        ->toThrow(ContactUpdateTargetNotFound::class)
        ->and($fake->contacts())->toHaveCount(1);
});

it('returns complete indexed outcomes for a partially successful fake batch update', function () {
    $fake = FakeContactWriter::make()->withContacts(new Contact(1, OptionalValue::present([])));

    $result = $fake->updateBatch(
        new ContactUpdate(1, ContactFields::fromArray(['firstname' => 'Ada'], ['firstname'])),
        new ContactUpdate(404, ContactFields::fromArray(['firstname' => 'Missing'], ['firstname'])),
    );

    expect($result->items[0]->requestIndex)->toBe(0)
        ->and($result->items[0]->outcome)->toBe(BatchItemOutcome::Successful)
        ->and($result->items[0]->contact?->remoteId)->toBe(1)
        ->and($result->items[1]->requestIndex)->toBe(1)
        ->and($result->items[1]->outcome)->toBe(BatchItemOutcome::Failed)
        ->and($result->items[1]->failureKind)->toBe(FailureKind::TargetNotFound);
});
