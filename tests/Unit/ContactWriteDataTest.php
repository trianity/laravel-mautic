<?php

declare(strict_types=1);

use Trianity\LaravelMautic\Exceptions\InvalidWriteRequest;
use Trianity\LaravelMautic\Read\ReadOperation;
use Trianity\LaravelMautic\Write\ContactFields;
use Trianity\LaravelMautic\Write\MutationOperation;

it('preserves explicit null false zero and empty string while omitting absent fields', function () {
    $fields = ContactFields::fromArray(
        [
            'firstname' => null,
            'subscribed' => false,
            'score' => 0,
            'company' => '',
        ],
        allowed: ['firstname', 'subscribed', 'score', 'company', 'lastname'],
    );

    expect($fields->toPayload())->toBe([
        'firstname' => null,
        'subscribed' => false,
        'score' => 0,
        'company' => '',
    ])->not->toHaveKey('lastname');
});

it('rejects fields outside the host allowlist and protected side-effect fields', function (array $values, array $allowed) {
    expect(fn () => ContactFields::fromArray($values, $allowed))
        ->toThrow(InvalidWriteRequest::class);
})->with([
    'not allowlisted' => [['email' => 'person@example.test', 'password' => 'secret'], ['email']],
    'DNC' => [['doNotContact' => []], ['doNotContact']],
    'tags' => [['tags' => ['customer']], ['tags']],
    'remote id' => [['id' => 42], ['id']],
]);

it('declares mutation retry and ambiguity safety explicitly', function () {
    expect(MutationOperation::CreateContact->safety()->sideEffectFree)->toBeFalse()
        ->and(MutationOperation::CreateContact->safety()->mayRetry)->toBeFalse()
        ->and(MutationOperation::CreateContact->safety()->ambiguousOnTransportFailure)->toBeTrue()
        ->and(MutationOperation::UpdateContact->safety()->mayRetry)->toBeFalse()
        ->and(MutationOperation::AddContactToSegment->safety()->ambiguousOnTransportFailure)->toBeTrue()
        ->and(ReadOperation::FindContact->safety()->sideEffectFree)->toBeTrue()
        ->and(ReadOperation::FindContact->safety()->mayRetry)->toBeTrue()
        ->and(ReadOperation::FindContact->safety()->ambiguousOnTransportFailure)->toBeFalse();
});
