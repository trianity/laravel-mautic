<?php

declare(strict_types=1);

use Trianity\LaravelMautic\Data\Contact;
use Trianity\LaravelMautic\Data\OptionalValue;
use Trianity\LaravelMautic\Exceptions\InvalidFieldMapping;
use Trianity\LaravelMautic\Fields\FieldChangeState;
use Trianity\LaravelMautic\Fields\FieldMapper;
use Trianity\LaravelMautic\Fields\FieldOwnership;
use Trianity\LaravelMautic\Fields\FieldRule;
use Trianity\LaravelMautic\Fields\HostContactFields;
use Trianity\LaravelMautic\Fields\SharedFieldResolution;

it('resolves host Mautic and shared field ownership while preserving value intent', function () {
    $mapper = new FieldMapper(
        new FieldRule('first_name', 'firstname', FieldOwnership::Host),
        new FieldRule('company', 'company', FieldOwnership::Mautic),
        new FieldRule('phone', 'phone', FieldOwnership::Shared, SharedFieldResolution::HostWins),
        new FieldRule('language', 'preferred_locale', FieldOwnership::Shared, SharedFieldResolution::MauticWins),
        new FieldRule('last_name', 'lastname', FieldOwnership::Host),
    );
    $host = HostContactFields::fromArray([
        'first_name' => null,
        'company' => 'Old Company',
        'phone' => '+361234',
    ], ['first_name', 'company', 'phone', 'language', 'last_name']);
    $remote = new Contact(42, OptionalValue::present([
        'firstname' => OptionalValue::present('Ada'),
        'company' => OptionalValue::null(),
        'phone' => OptionalValue::present('+361234'),
        'preferred_locale' => OptionalValue::present('hu'),
    ]));

    $resolution = $mapper->resolve($host, $remote);

    expect($resolution->outbound('firstname')->state)->toBe(FieldChangeState::Clear)
        ->and($resolution->outbound('phone')->state)->toBe(FieldChangeState::Unchanged)
        ->and($resolution->outbound('lastname')->state)->toBe(FieldChangeState::Unchanged)
        ->and($resolution->inbound('company')->state)->toBe(FieldChangeState::Clear)
        ->and($resolution->inbound('language')->state)->toBe(FieldChangeState::Set)
        ->and($resolution->inbound('language')->value())->toBe('hu')
        ->and($resolution->outboundValues())->toBe(['firstname' => null])
        ->and($resolution->inboundValues())->toBe(['company' => null, 'language' => 'hu']);
});

it('requires explicit allowlists and shared ownership resolution and excludes side-effect state', function () {
    expect(fn () => HostContactFields::fromArray(['email' => 'person@example.test', 'password' => 'secret'], ['email']))
        ->toThrow(InvalidFieldMapping::class)
        ->and(fn () => new FieldRule('status', 'tags', FieldOwnership::Host))
        ->toThrow(InvalidFieldMapping::class)
        ->and(fn () => new FieldRule('consent', 'donotcontact', FieldOwnership::Host))
        ->toThrow(InvalidFieldMapping::class)
        ->and(fn () => new FieldRule('segment', 'segments', FieldOwnership::Mautic))
        ->toThrow(InvalidFieldMapping::class)
        ->and(fn () => new FieldRule('phone', 'phone', FieldOwnership::Shared))
        ->toThrow(InvalidFieldMapping::class);
});
