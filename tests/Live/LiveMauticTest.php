<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Illuminate\Config\Repository;
use Mautic\Auth\BasicAuth;
use Trianity\LaravelMautic\Contracts\OAuthClientCredentialsProvider;
use Trianity\LaravelMautic\Data\OAuthClientCredentials;
use Trianity\LaravelMautic\Data\OAuthToken;
use Trianity\LaravelMautic\Data\ValuePresence;
use Trianity\LaravelMautic\Http\GuzzleHttpClientFactory;
use Trianity\LaravelMautic\MauticConnection;
use Trianity\LaravelMautic\OAuth\HttpOAuthTokenEndpoint;
use Trianity\LaravelMautic\OAuth\SystemClock;
use Trianity\LaravelMautic\Write\ContactFields;

/** @return array{url: string, username: string, password: string, prefix: string, version: string} */
function liveMauticConfiguration(): array
{
    if (getenv('MAUTIC_LIVE_TESTS') !== '1') {
        test()->markTestSkipped('Set MAUTIC_LIVE_TESTS=1 to enable disposable real-server tests.');
    }
    if (getenv('MAUTIC_LIVE_DISPOSABLE') !== 'I_UNDERSTAND_THIS_DELETES_OWN_FIXTURES') {
        test()->fail('The exact MAUTIC_LIVE_DISPOSABLE acknowledgement is required.');
    }
    $url = getenv('MAUTIC_LIVE_URL');
    $username = getenv('MAUTIC_LIVE_USERNAME');
    $password = getenv('MAUTIC_LIVE_PASSWORD');
    $prefix = getenv('MAUTIC_LIVE_FIXTURE_PREFIX');
    $installPath = getenv('MAUTIC_LIVE_INSTALL_PATH');
    $expectedVersion = getenv('MAUTIC_LIVE_SERVER_VERSION');
    if (! is_string($url) || ! is_string($username) || ! is_string($password)
        || ! is_string($prefix) || ! is_string($installPath) || ! is_string($expectedVersion)) {
        test()->fail('All required MAUTIC_LIVE_* values must be configured.');
    }
    $host = parse_url($url, PHP_URL_HOST);
    if (! is_string($host) || ! in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
        test()->fail('Live tests accept only a loopback Mautic URL.');
    }
    if (preg_match('/^lm-live-[a-z0-9-]+$/', $prefix) !== 1) {
        test()->fail('The live fixture prefix must match lm-live-[a-z0-9-]+.');
    }
    $installedPath = rtrim($installPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'vendor/composer/installed.json';
    $installed = is_file($installedPath)
        ? json_decode((string) file_get_contents($installedPath), true, flags: JSON_THROW_ON_ERROR)
        : null;
    $packages = is_array($installed) && is_array($installed['packages'] ?? null)
        ? $installed['packages']
        : [];
    $installedVersion = null;
    foreach ($packages as $package) {
        if (is_array($package) && ($package['name'] ?? null) === 'mautic/core-lib'
            && is_string($package['version'] ?? null)) {
            $installedVersion = $package['version'];
            break;
        }
    }
    if (! is_string($installedVersion) || ! hash_equals($expectedVersion, $installedVersion)) {
        test()->fail('The installed local Mautic core-lib version does not match MAUTIC_LIVE_SERVER_VERSION.');
    }

    return compact('url', 'username', 'password', 'prefix') + ['version' => $installedVersion];
}

/** @param array{url: string, username: string, password: string, prefix: string, version: string} $configuration */
function liveMauticConnection(array $configuration): MauticConnection
{
    $auth = new BasicAuth(new Client([
        'timeout' => 10,
        'connect_timeout' => 3,
        'verify' => true,
        'allow_redirects' => false,
    ]));
    $auth->setup($configuration['username'], $configuration['password']);

    return new MauticConnection('live', rtrim($configuration['url'], '/').'/api/', $auth);
}

/** @param array{url: string, username: string, password: string, prefix: string, version: string} $configuration */
function deleteOwnLiveContact(MauticConnection $connection, array $configuration, int $remoteId, string $email): void
{
    $stored = $connection->contactReads()->find($remoteId)->field('email');
    if ($stored->presence !== ValuePresence::Value || ! is_string($stored->value())
        || ! hash_equals($email, $stored->value()) || ! str_starts_with($email, $configuration['prefix'].'.')) {
        test()->fail('Refusing to delete a contact that is not the current test fixture.');
    }
    $response = $connection->contacts()->delete($remoteId);
    if (! is_array($response) || array_key_exists('errors', $response)) {
        test()->fail('The dedicated live fixture could not be deleted safely.');
    }
}

it('verifies Basic Auth partial PATCH DNC and optional manual segment behavior on a disposable server', function () {
    $configuration = liveMauticConfiguration();
    $connection = liveMauticConnection($configuration);
    $email = $configuration['prefix'].'.'.bin2hex(random_bytes(8)).'@example.test';
    $remoteId = null;
    $segmentId = filter_var(getenv('MAUTIC_LIVE_STATIC_SEGMENT_ID'), FILTER_VALIDATE_INT) ?: null;
    $segmentAdded = false;

    try {
        $created = $connection->contactWrites()->create(ContactFields::fromArray([
            'email' => $email,
            'firstname' => 'LiveBefore',
            'lastname' => 'PreservedByPatch',
        ], ['email', 'firstname', 'lastname']));
        $remoteId = $created->remoteId;
        $connection->contactWrites()->update($remoteId, ContactFields::fromArray(
            ['firstname' => 'LiveAfter'],
            ['firstname'],
        ));
        $patched = $connection->contactReads()->find($remoteId);
        expect($patched->field('firstname')->value())->toBe('LiveAfter')
            ->and($patched->field('lastname')->value())->toBe('PreservedByPatch');

        $dncResponse = $connection->contacts()->addDnc($remoteId, 'email');
        expect($dncResponse)->toBeArray()->not->toHaveKey('errors');
        $dnc = $connection->contactReads()->find($remoteId)->doNotContact;
        expect($dnc->presence)->toBe(ValuePresence::Value)
            ->and(array_column($dnc->value(), 'channel'))->toContain('email');

        if (is_int($segmentId) && $segmentId > 0) {
            $connection->contactWrites()->addToSegment($remoteId, $segmentId);
            $segmentAdded = true;
            expect(array_column($connection->contactReads()->segments($remoteId)->memberships, 'remoteId'))
                ->toContain($segmentId);
            $connection->contactWrites()->removeFromSegment($remoteId, $segmentId);
            $segmentAdded = false;
        }
    } finally {
        if (is_int($remoteId)) {
            if ($segmentAdded && is_int($segmentId)) {
                $connection->contactWrites()->removeFromSegment($remoteId, $segmentId);
            }
            deleteOwnLiveContact($connection, $configuration, $remoteId, $email);
        }
    }
});

it('verifies configured dynamic segment convergence within an explicit bounded window', function () {
    $configuration = liveMauticConfiguration();
    $segmentId = filter_var(getenv('MAUTIC_LIVE_DYNAMIC_SEGMENT_ID'), FILTER_VALIDATE_INT) ?: null;
    $field = getenv('MAUTIC_LIVE_DYNAMIC_FIELD');
    $value = getenv('MAUTIC_LIVE_DYNAMIC_VALUE');
    if (! is_int($segmentId) || $segmentId < 1 || ! is_string($field) || $field === '' || ! is_string($value)) {
        test()->markTestSkipped('Dynamic segment fixture variables are not configured.');
    }
    $connection = liveMauticConnection($configuration);
    $email = $configuration['prefix'].'.dynamic.'.bin2hex(random_bytes(8)).'@example.test';
    $remoteId = null;

    try {
        $remoteId = $connection->contactWrites()->create(ContactFields::fromArray([
            'email' => $email,
            $field => $value,
        ], ['email', $field]))->remoteId;
        $deadline = time() + max(5, min(120, (int) (getenv('MAUTIC_LIVE_DYNAMIC_WAIT_SECONDS') ?: 30)));
        $present = false;
        do {
            $present = in_array(
                $segmentId,
                array_column($connection->contactReads()->segments($remoteId)->memberships, 'remoteId'),
                true,
            );
            if (! $present) {
                usleep(500_000);
            }
        } while (! $present && time() < $deadline);
        expect($present)->toBeTrue('The configured dynamic segment did not converge before the deadline.');
    } finally {
        if (is_int($remoteId)) {
            deleteOwnLiveContact($connection, $configuration, $remoteId, $email);
        }
    }
});

it('verifies an opt-in local webhook capture with the configured signature secret', function () {
    $configuration = liveMauticConfiguration();
    $capturePath = getenv('MAUTIC_LIVE_WEBHOOK_CAPTURE_FILE');
    $secret = getenv('MAUTIC_LIVE_WEBHOOK_SECRET');
    if (! is_string($capturePath) || ! str_starts_with($capturePath, '/') || ! is_file($capturePath)
        || ! is_string($secret) || $secret === '') {
        test()->markTestSkipped('The local webhook JSONL capture and secret are not configured.');
    }
    $offset = filesize($capturePath);
    if (! is_int($offset)) {
        test()->fail('The webhook capture size could not be read.');
    }
    $connection = liveMauticConnection($configuration);
    $email = $configuration['prefix'].'.webhook.'.bin2hex(random_bytes(8)).'@example.test';
    $remoteId = null;

    try {
        $remoteId = $connection->contactWrites()->create(ContactFields::fromArray([
            'email' => $email,
            'firstname' => 'WebhookBefore',
        ], ['email', 'firstname']))->remoteId;
        $connection->contactWrites()->update($remoteId, ContactFields::fromArray(
            ['firstname' => 'WebhookAfter'],
            ['firstname'],
        ));
        $deadline = time() + max(5, min(60, (int) (getenv('MAUTIC_LIVE_WEBHOOK_WAIT_SECONDS') ?: 20)));
        $matched = false;
        do {
            clearstatcache(true, $capturePath);
            $content = file_get_contents($capturePath, false, null, $offset);
            foreach (explode("\n", is_string($content) ? $content : '') as $line) {
                if ($line === '') {
                    continue;
                }
                $capture = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                $body = is_array($capture) && is_string($capture['body_base64'] ?? null)
                    ? base64_decode($capture['body_base64'], true)
                    : false;
                $signature = is_array($capture) ? ($capture['signature'] ?? null) : null;
                if (! is_string($body) || ! is_string($signature)
                    || ! hash_equals(base64_encode(hash_hmac('sha256', $body, $secret, true)), $signature)) {
                    continue;
                }
                $matched = str_contains($body, '"id":'.$remoteId)
                    || str_contains($body, '"id": '.$remoteId);
                if ($matched) {
                    break 2;
                }
            }
            if (! $matched) {
                usleep(500_000);
            }
        } while (time() < $deadline);
        expect($matched)->toBeTrue('No correctly signed webhook for the dedicated fixture was captured.');
    } finally {
        if (is_int($remoteId)) {
            deleteOwnLiveContact($connection, $configuration, $remoteId, $email);
        }
    }
});

it('verifies OAuth refresh only with a dedicated rotating credential output file', function () {
    $configuration = liveMauticConfiguration();
    if (getenv('MAUTIC_LIVE_OAUTH_TESTS') !== '1') {
        test()->markTestSkipped('OAuth rotation requires the separate MAUTIC_LIVE_OAUTH_TESTS=1 opt-in.');
    }
    $clientId = getenv('MAUTIC_LIVE_OAUTH_CLIENT_ID');
    $clientSecret = getenv('MAUTIC_LIVE_OAUTH_CLIENT_SECRET');
    $refreshToken = getenv('MAUTIC_LIVE_OAUTH_REFRESH_TOKEN');
    $output = getenv('MAUTIC_LIVE_OAUTH_ROTATED_TOKEN_FILE');
    if (! is_string($clientId) || ! is_string($clientSecret) || ! is_string($refreshToken)
        || ! is_string($output) || ! str_starts_with($output, '/')) {
        test()->fail('OAuth live variables and an absolute rotated-token output path are required.');
    }
    $config = new Repository(['mautic' => ['connections' => ['live' => [
        'url' => $configuration['url'],
        'auth' => 'oauth2',
        'allow_http' => str_starts_with($configuration['url'], 'http://'),
        'timeout' => 10,
        'connect_timeout' => 3,
        'oauth' => [
            'client_id' => $clientId,
            'client_secret_env' => 'MAUTIC_LIVE_OAUTH_CLIENT_SECRET',
            'redirect_uri' => rtrim($configuration['url'], '/').'/oauth/callback',
        ],
    ]]]]);
    $credentials = new class($clientId, $clientSecret) implements OAuthClientCredentialsProvider
    {
        public function __construct(private string $id, private string $secret) {}

        public function forConnection(string $connection): OAuthClientCredentials
        {
            return new OAuthClientCredentials($this->id, $this->secret);
        }
    };
    $endpoint = new HttpOAuthTokenEndpoint(
        $config,
        new GuzzleHttpClientFactory,
        $credentials,
        new SystemClock,
    );
    $rotated = $endpoint->refresh('live', new OAuthToken('expired', $refreshToken, 1));
    $handle = fopen($output, 'x+b');
    if ($handle === false) {
        test()->fail('The rotated-token output must be a new exclusively created file.');
    }
    if (! chmod($output, 0600)) {
        fclose($handle);
        test()->fail('The rotated-token output could not be restricted before writing the secret.');
    }
    $written = fwrite($handle, $rotated->refreshToken);
    $flushed = fflush($handle);
    fclose($handle);

    expect($written)->toBeGreaterThan(0)
        ->and($flushed)->toBeTrue()
        ->and($rotated->accessToken)->not->toBe('')
        ->and($rotated->expiresAt)->toBeGreaterThan(time());
});
