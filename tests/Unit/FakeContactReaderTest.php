<?php

declare(strict_types=1);

use Trianity\LaravelMautic\Contracts\ContactReader;
use Trianity\LaravelMautic\Data\Contact;
use Trianity\LaravelMautic\Data\OptionalValue;
use Trianity\LaravelMautic\Data\SegmentMembership;
use Trianity\LaravelMautic\Exceptions\ContactNotFound;
use Trianity\LaravelMautic\Exceptions\MauticReadException;
use Trianity\LaravelMautic\Fakes\FakeContactReader;
use Trianity\LaravelMautic\Read\ContactSearch;
use Trianity\LaravelMautic\Read\ReadFailureKind;

it('provides deterministic contact search and membership reads without selecting a match', function () {
    $first = new Contact(1, OptionalValue::present(['email' => OptionalValue::present('same@example.test')]));
    $second = new Contact(2, OptionalValue::present(['email' => OptionalValue::present('same@example.test')]));
    $fake = FakeContactReader::make()
        ->withContacts($first, $second)
        ->withSearchResult('email:same@example.test', $first, $second)
        ->withSegmentMemberships(1, new SegmentMembership(9, 'Customers', 'customers'));

    expect($fake)->toBeInstanceOf(ContactReader::class)
        ->and($fake->find(1))->toBe($first)
        ->and($fake->search(new ContactSearch('email:same@example.test', 0, 25))->contacts)->toBe([$first, $second])
        ->and($fake->segments(1)->memberships[0]->name)->toBe('Customers')
        ->and($fake->segments(2)->memberships)->toBe([]);
});

it('can deterministically fail the next read with a normalized error', function () {
    $fake = FakeContactReader::make()->failNext(ReadFailureKind::Timeout);

    expect(fn () => $fake->find(1))
        ->toThrow(fn (MauticReadException $exception) => $exception->kind === ReadFailureKind::Timeout);

    expect(fn () => $fake->find(1))->toThrow(ContactNotFound::class);
});
