<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Trianity\LaravelMautic\Configuration\ConnectionConfig;
use Trianity\LaravelMautic\Http\GuzzleHttpClientFactory;

it('configures bounded requests with TLS verification and no redirects', function () {
    $config = ConnectionConfig::fromArray('main', [
        'url' => 'https://mautic.example.test',
        'username' => 'integration',
        'password' => 'test-secret',
        'timeout' => 7,
        'connect_timeout' => 2,
    ]);

    $client = (new GuzzleHttpClientFactory)->make($config);

    expect($client)->toBeInstanceOf(Client::class);
    expect($client->getConfig('timeout'))->toBe(7.0);
    expect($client->getConfig('connect_timeout'))->toBe(2.0);
    expect($client->getConfig('verify'))->toBeTrue();
    expect($client->getConfig('allow_redirects'))->toBeFalse();
});
