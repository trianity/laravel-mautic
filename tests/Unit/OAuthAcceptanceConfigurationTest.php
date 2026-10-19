<?php

declare(strict_types=1);

use Trianity\LaravelMautic\Tests\Support\OAuthAcceptanceConfiguration;
use Trianity\LaravelMautic\Tests\Support\OAuthAcceptanceHarness;

/** @return array<string, string> */
function validOAuthAcceptanceEnvironment(string $installPath, string $certificatePath): array
{
    return [
        'MAUTIC_OAUTH_ACCEPTANCE_TESTS' => '1',
        'MAUTIC_LIVE_URL' => 'https://mautic.test',
        'MAUTIC_LIVE_INSTALL_PATH' => $installPath,
        'MAUTIC_LIVE_SERVER_VERSION' => '7.2.1',
        'MAUTIC_LIVE_CA_BUNDLE' => $certificatePath,
        'MAUTIC_LIVE_OAUTH_CLIENT_ID' => 'dedicated-client-id',
        'MAUTIC_LIVE_OAUTH_CLIENT_SECRET' => 'dedicated-client-secret',
        'MAUTIC_LIVE_OAUTH_REDIRECT_URI' => 'http://127.0.0.1:8765/oauth/callback',
    ];
}

/** @return array{string, string} */
function oauthAcceptanceFixture(): array
{
    $directory = sys_get_temp_dir().'/mautic-oauth-acceptance-'.bin2hex(random_bytes(6));
    mkdir($directory.'/vendor/composer', 0700, true);
    file_put_contents($directory.'/vendor/composer/installed.json', json_encode([
        'packages' => [[
            'name' => 'mautic/core-lib',
            'version' => '7.2.1',
        ]],
    ], JSON_THROW_ON_ERROR));
    $certificate = $directory.'/local-ca.pem';
    file_put_contents($certificate, 'test certificate');

    return [$directory, $certificate];
}

function removeOAuthAcceptanceFixture(string $directory): void
{
    unlink($directory.'/vendor/composer/installed.json');
    unlink($directory.'/local-ca.pem');
    rmdir($directory.'/vendor/composer');
    rmdir($directory.'/vendor');
    rmdir($directory);
}

it('accepts only the exact local HTTPS origin with exclusively loopback resolution', function () {
    [$directory, $certificate] = oauthAcceptanceFixture();

    try {
        $configuration = OAuthAcceptanceConfiguration::fromArray(
            validOAuthAcceptanceEnvironment($directory, $certificate),
            static fn (string $host): array => $host === 'mautic.test' ? ['127.0.0.1', '::1'] : [],
        );

        expect($configuration->origin)->toBe('https://mautic.test')
            ->and($configuration->serverVersion)->toBe('7.2.1')
            ->and($configuration->redirectUri)->toBe('http://127.0.0.1:8765/oauth/callback');
    } finally {
        removeOAuthAcceptanceFixture($directory);
    }
});

it('rejects lookalike hosts and mixed non-loopback resolution', function (string $url, array $addresses) {
    [$directory, $certificate] = oauthAcceptanceFixture();
    $environment = validOAuthAcceptanceEnvironment($directory, $certificate);
    $environment['MAUTIC_LIVE_URL'] = $url;

    try {
        expect(fn () => OAuthAcceptanceConfiguration::fromArray(
            $environment,
            static fn (): array => $addresses,
        ))->toThrow(RuntimeException::class);
    } finally {
        removeOAuthAcceptanceFixture($directory);
    }
})->with([
    'arbitrary test host' => ['https://other.test', ['127.0.0.1']],
    'subdomain lookalike' => ['https://child.mautic.test', ['127.0.0.1']],
    'HTTP origin' => ['http://mautic.test', ['127.0.0.1']],
    'mixed DNS answers' => ['https://mautic.test', ['127.0.0.1', '192.0.2.10']],
    'no DNS answer' => ['https://mautic.test', []],
]);

it('requires dedicated OAuth values and never falls back to Basic Auth credentials', function () {
    [$directory, $certificate] = oauthAcceptanceFixture();
    $environment = validOAuthAcceptanceEnvironment($directory, $certificate) + [
        'MAUTIC_LIVE_USERNAME' => 'basic-user',
        'MAUTIC_LIVE_PASSWORD' => 'basic-password',
    ];
    unset($environment['MAUTIC_LIVE_OAUTH_CLIENT_ID'], $environment['MAUTIC_LIVE_OAUTH_CLIENT_SECRET']);

    try {
        expect(fn () => OAuthAcceptanceConfiguration::fromArray(
            $environment,
            static fn (): array => ['127.0.0.1'],
        ))->toThrow(RuntimeException::class, 'OAuth acceptance requires MAUTIC_LIVE_OAUTH_CLIENT_ID.');
    } finally {
        removeOAuthAcceptanceFixture($directory);
    }
});

it('prepares encrypted persistent local state without exposing the context or client secret', function () {
    [$directory, $certificate] = oauthAcceptanceFixture();
    $storage = $directory.'/acceptance-storage';

    try {
        $configuration = OAuthAcceptanceConfiguration::fromArray(
            validOAuthAcceptanceEnvironment($directory, $certificate),
            static fn (): array => ['127.0.0.1'],
        );
        $harness = new OAuthAcceptanceHarness($configuration, $storage);
        $url = $harness->prepareAuthorization();
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        expect($url)->toStartWith('https://mautic.test/oauth/v2/authorize?')
            ->and($url)->not->toContain('dedicated-client-secret', 'context')
            ->and($query['client_id'] ?? null)->toBe('dedicated-client-id')
            ->and($query['redirect_uri'] ?? null)->toBe(OAuthAcceptanceConfiguration::CALLBACK_URI)
            ->and($query['state'] ?? null)->toBeString()->not->toBe('')
            ->and(fileperms($storage.'/encryption.key') & 0777)->toBe(0600)
            ->and(fileperms($storage.'/oauth.sqlite') & 0777)->toBe(0600)
            ->and(fileperms($storage.'/authorization-context.json') & 0777)->toBe(0600);
    } finally {
        foreach (glob($storage.'/*') ?: [] as $path) {
            unlink($path);
        }
        if (is_dir($storage)) {
            rmdir($storage);
        }
        removeOAuthAcceptanceFixture($directory);
    }
});
