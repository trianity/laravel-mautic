<?php

declare(strict_types=1);

use Trianity\LaravelMautic\Data\Contact;
use Trianity\LaravelMautic\Data\OptionalValue;
use Trianity\LaravelMautic\Data\ValuePresence;
use Trianity\LaravelMautic\Exceptions\InvalidReadRequest;
use Trianity\LaravelMautic\Read\ContactSearch;

it('preserves missing null and empty contact values', function () {
    $contact = new Contact(
        remoteId: 42,
        fields: OptionalValue::present([
            'missing-neighbour' => OptionalValue::missing(),
            'null-value' => OptionalValue::null(),
            'empty-value' => OptionalValue::present(''),
        ]),
        tags: OptionalValue::present([]),
        doNotContact: OptionalValue::null(),
    );

    expect($contact->field('absent')->presence)->toBe(ValuePresence::Missing)
        ->and($contact->field('null-value')->presence)->toBe(ValuePresence::Null)
        ->and($contact->field('empty-value')->value())->toBe('')
        ->and($contact->tags->presence)->toBe(ValuePresence::Value)
        ->and($contact->tags->value())->toBe([])
        ->and($contact->doNotContact->presence)->toBe(ValuePresence::Null);
});

it('requires bounded explicit search pagination', function (int $offset, int $limit) {
    expect(fn () => new ContactSearch('email:test@example.test', $offset, $limit))
        ->toThrow(InvalidReadRequest::class);
})->with([
    'negative offset' => [-1, 25],
    'zero limit' => [0, 0],
    'limit over one hundred' => [0, 101],
]);
