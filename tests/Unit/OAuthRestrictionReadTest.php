<?php

declare(strict_types=1);

use Trianity\LaravelMautic\Data\Contact;
use Trianity\LaravelMautic\Data\DoNotContactEntry;
use Trianity\LaravelMautic\Data\OptionalValue;
use Trianity\LaravelMautic\Data\ValuePresence;
use Trianity\LaravelMautic\Tests\Support\OAuthAcceptanceConfiguration;
use Trianity\LaravelMautic\Tests\Support\OAuthRestrictionReadConfiguration;
use Trianity\LaravelMautic\Tests\Support\OAuthRestrictionReadEvidence;
use Trianity\LaravelMautic\Tests\Support\OAuthRestrictionReadVerifier;

function restrictionReadConfiguration(): OAuthRestrictionReadConfiguration
{
    $reflection = new ReflectionClass(OAuthAcceptanceConfiguration::class);
    $oauth = $reflection->newInstanceWithoutConstructor();

    return new OAuthRestrictionReadConfiguration(
        $oauth,
        321,
        'reference@example.test',
        654,
        'Tiltás teszt Ω 01',
    );
}

function restrictionReadEntry(
    int $id = 654,
    ?OptionalValue $channelId = null,
    string $comments = 'Tiltás teszt Ω 01',
    string|OptionalValue $dateAdded = '2026-10-23T08:30:00+02:00',
): DoNotContactEntry {
    return new DoNotContactEntry(
        OptionalValue::present($id),
        'email',
        OptionalValue::present(3),
        is_string($dateAdded) ? OptionalValue::present($dateAdded) : $dateAdded,
        $channelId ?? OptionalValue::null(),
        OptionalValue::present($comments),
    );
}

/** @param list<DoNotContactEntry> $entries */
function restrictionReadContact(array $entries): Contact
{
    return new Contact(
        321,
        OptionalValue::present(['email' => OptionalValue::present('reference@example.test')]),
        doNotContact: OptionalValue::present($entries),
    );
}

it('keeps the read-only opt-in disabled without constructing OAuth configuration', function () {
    $oauthFactoryCalled = false;

    expect(OAuthRestrictionReadConfiguration::enabled([]))->toBeFalse()
        ->and(fn () => OAuthRestrictionReadConfiguration::fromArray(
            [],
            function () use (&$oauthFactoryCalled): never {
                $oauthFactoryCalled = true;
                throw new LogicException('OAuth configuration must not be constructed.');
            },
        ))->toThrow(RuntimeException::class, 'MAUTIC_OAUTH_RESTRICTION_READ_TESTS=1')
        ->and($oauthFactoryCalled)->toBeFalse();
});

it('rejects incomplete or invalid reference configuration before OAuth construction', function (array $changes) {
    $environment = [
        'MAUTIC_OAUTH_RESTRICTION_READ_TESTS' => '1',
        'MAUTIC_LIVE_OAUTH_RESTRICTION_CONTACT_ID' => '321',
        'MAUTIC_LIVE_OAUTH_RESTRICTION_CONTACT_EMAIL' => 'reference@example.test',
        'MAUTIC_LIVE_OAUTH_RESTRICTION_DNC_ID' => '654',
        'MAUTIC_LIVE_OAUTH_RESTRICTION_COMMENTS' => 'Tiltás teszt Ω 01',
    ];
    foreach ($changes as $key => $value) {
        if ($value === null) {
            unset($environment[$key]);
        } else {
            $environment[$key] = $value;
        }
    }
    $oauthFactoryCalled = false;

    expect(fn () => OAuthRestrictionReadConfiguration::fromArray(
        $environment,
        function () use (&$oauthFactoryCalled): never {
            $oauthFactoryCalled = true;
            throw new LogicException('OAuth configuration must not be constructed.');
        },
    ))->toThrow(RuntimeException::class)
        ->and($oauthFactoryCalled)->toBeFalse();
})->with([
    'missing contact ID' => [['MAUTIC_LIVE_OAUTH_RESTRICTION_CONTACT_ID' => null]],
    'invalid contact ID' => [['MAUTIC_LIVE_OAUTH_RESTRICTION_CONTACT_ID' => '0']],
    'invalid email' => [['MAUTIC_LIVE_OAUTH_RESTRICTION_CONTACT_EMAIL' => 'not-an-email']],
    'missing DNC ID' => [['MAUTIC_LIVE_OAUTH_RESTRICTION_DNC_ID' => null]],
    'blank comments' => [['MAUTIC_LIVE_OAUTH_RESTRICTION_COMMENTS' => '  ']],
]);

it('selects one manual restriction among unrelated entries and accepts explicit null channel ID', function () {
    $contact = restrictionReadContact([
        restrictionReadEntry(111),
        restrictionReadEntry(),
        restrictionReadEntry(777),
    ]);

    (new OAuthRestrictionReadVerifier)->verify($contact, $contact, restrictionReadConfiguration());

    expect(true)->toBeTrue();
});

it('distinguishes an omitted channel ID from an explicit null value', function () {
    $contact = restrictionReadContact([
        restrictionReadEntry(channelId: OptionalValue::missing()),
    ]);

    expect(fn () => (new OAuthRestrictionReadVerifier)->verify(
        $contact,
        $contact,
        restrictionReadConfiguration(),
    ))->toThrow(RuntimeException::class, 'channel ID');
});

it('rejects missing and duplicate matching restriction IDs', function (array $entries) {
    $contact = restrictionReadContact($entries);

    expect(fn () => (new OAuthRestrictionReadVerifier)->verify(
        $contact,
        $contact,
        restrictionReadConfiguration(),
    ))->toThrow(RuntimeException::class, 'exactly one');
})->with([
    'missing' => [[restrictionReadEntry(111)]],
    'duplicate' => [[restrictionReadEntry(), restrictionReadEntry()]],
]);

it('compares Unicode comments exactly', function () {
    $contact = restrictionReadContact([
        restrictionReadEntry(comments: 'Tiltas teszt Ω 01'),
    ]);

    expect(fn () => (new OAuthRestrictionReadVerifier)->verify(
        $contact,
        $contact,
        restrictionReadConfiguration(),
    ))->toThrow(RuntimeException::class, 'comments');
});

it('accepts absent date metadata and valid serializer dates but rejects invalid values', function (string|OptionalValue $dateAdded, bool $valid) {
    $contact = restrictionReadContact([restrictionReadEntry(dateAdded: $dateAdded)]);
    $verify = fn () => (new OAuthRestrictionReadVerifier)->verify(
        $contact,
        $contact,
        restrictionReadConfiguration(),
    );

    if ($valid) {
        $verify();
        expect(true)->toBeTrue();

        return;
    }

    expect($verify)->toThrow(RuntimeException::class, 'date added');
})->with([
    'RFC3339 with offset' => ['2026-10-23T08:30:00+02:00', true],
    'RFC3339 UTC offset' => ['2026-10-23T06:30:00+00:00', true],
    'missing' => [OptionalValue::missing(), true],
    'null' => [OptionalValue::null(), true],
    'database format' => ['2026-10-23 06:30:00', false],
    'impossible date' => ['2026-02-30T06:30:00+00:00', false],
    'blank' => ['', false],
]);

it('requires missing date metadata for the configured Mautic reference fixture', function () {
    $missing = restrictionReadContact([restrictionReadEntry(dateAdded: OptionalValue::missing())]);
    $value = restrictionReadContact([restrictionReadEntry()]);

    (new OAuthRestrictionReadVerifier)->verify(
        $missing,
        $missing,
        restrictionReadConfiguration(),
        expectedDateAddedPresence: ValuePresence::Missing,
    );

    expect(fn () => (new OAuthRestrictionReadVerifier)->verify(
        $value,
        $value,
        restrictionReadConfiguration(),
        expectedDateAddedPresence: ValuePresence::Missing,
    ))->toThrow(RuntimeException::class, 'date added');
});

it('fails read-only when date presence changes between responses', function () {
    $first = restrictionReadContact([restrictionReadEntry(dateAdded: OptionalValue::missing())]);
    $second = restrictionReadContact([restrictionReadEntry(dateAdded: OptionalValue::null())]);

    expect(fn () => (new OAuthRestrictionReadVerifier)->verify(
        $first,
        $second,
        restrictionReadConfiguration(),
    ))->toThrow(RuntimeException::class, 'changed between reads');
});

it('fails read-only when the selected restriction changes on the second response', function () {
    $first = restrictionReadContact([restrictionReadEntry()]);
    $second = restrictionReadContact([restrictionReadEntry(dateAdded: '2026-10-23T08:31:00+02:00')]);

    expect(fn () => (new OAuthRestrictionReadVerifier)->verify(
        $first,
        $second,
        restrictionReadConfiguration(),
    ))->toThrow(RuntimeException::class, 'changed between reads');
});

it('stores only sanitized read evidence with restrictive permissions', function () {
    $directory = sys_get_temp_dir().'/mautic-restriction-read-'.bin2hex(random_bytes(6));

    try {
        $path = (new OAuthRestrictionReadEvidence)->write('passed', 3, true, $directory);
        $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        expect(fileperms($path) & 0777)->toBe(0600)
            ->and(basename($path))->toBe('restriction-read-corrected.json')
            ->and($payload)->toBe([
                'status' => 'passed',
                'request_attempts' => 3,
                'token_refreshed' => true,
                'mutation_requests' => 0,
            ]);
    } finally {
        if (isset($path) && is_file($path)) {
            unlink($path);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});
