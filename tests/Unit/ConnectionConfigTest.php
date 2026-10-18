<?php

declare(strict_types=1);

use Trianity\LaravelMautic\Configuration\ConnectionConfig;
use Trianity\LaravelMautic\Exceptions\InvalidConfiguration;

function connectionValues(array $overrides = []): array
{
    return array_replace([
        'url' => 'https://mautic.example.test',
        'username' => 'integration',
        'password' => 'test-secret',
    ], $overrides);
}

it('preserves subdirectories and avoids duplicate api suffixes', function (string $url, string $expected) {
    expect(ConnectionConfig::fromArray('main', connectionValues(['url' => $url]))->apiUrl())->toBe($expected);
})->with([
    ['https://mautic.example.test/', 'https://mautic.example.test/api/'],
    ['https://example.test/marketing/', 'https://example.test/marketing/api/'],
    ['https://example.test/marketing/api/', 'https://example.test/marketing/api/'],
]);

it('rejects unsafe or unsupported configuration', function (array $overrides) {
    expect(fn () => ConnectionConfig::fromArray('main', connectionValues($overrides)))
        ->toThrow(InvalidConfiguration::class);
})->with([
    [['url' => '']],
    [['url' => 'http://mautic.example.test']],
    [['url' => 'ftp://mautic.example.test']],
    [['url' => 'https://user:password@mautic.example.test']],
    [['url' => 'https://user@mautic.example.test']],
    [['url' => 'https://mautic.example.test?token=secret']],
    [['url' => 'https://mautic.example.test#fragment']],
    [['username' => ' ']],
    [['password' => ' ']],
    [['auth' => 'oauth2']],
    [['timeout' => 0]],
    [['timeout' => -1]],
    [['timeout' => '10']],
    [['timeout' => INF]],
    [['timeout' => NAN]],
    [['connect_timeout' => 0]],
    [['timeout' => 1, 'connect_timeout' => 3]],
]);

it('permits explicitly enabled HTTP for local development', function () {
    $config = ConnectionConfig::fromArray('local', connectionValues([
        'url' => 'http://localhost:8080',
        'allow_http' => true,
    ]));

    expect($config->apiUrl())->toBe('http://localhost:8080/api/');
});

it('does not include credentials in configuration exception messages', function () {
    try {
        ConnectionConfig::fromArray('main', connectionValues(['url' => 'not-a-url-secret']));
        $this->fail('Expected invalid configuration.');
    } catch (InvalidConfiguration $exception) {
        expect($exception->getMessage())->not->toContain('test-secret', 'not-a-url-secret');
    }
});

it('rejects blank connection names', function () {
    expect(fn () => ConnectionConfig::fromArray(' ', connectionValues()))
        ->toThrow(InvalidConfiguration::class);
});
