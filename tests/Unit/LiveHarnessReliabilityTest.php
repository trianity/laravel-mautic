<?php

declare(strict_types=1);

use Trianity\LaravelMautic\Data\OAuthToken;
use Trianity\LaravelMautic\Exceptions\OAuthTokenPersistenceUncertain;
use Trianity\LaravelMautic\Tests\Support\LiveWebhookCaptureMatcher;
use Trianity\LaravelMautic\Tests\Support\RotatedTokenOutput;

function captureLine(string $body, string $secret = 'secret'): string
{
    return json_encode([
        'body_base64' => base64_encode($body),
        'signature' => base64_encode(hash_hmac('sha256', $body, $secret, true)),
    ], JSON_THROW_ON_ERROR)."\n";
}

function updateBody(int $id, string $email, string $firstname, string $type = 'mautic.lead_post_save_update'): string
{
    return json_encode([$type => [[
        'lead' => ['id' => $id, 'fields' => ['all' => ['email' => $email, 'firstname' => $firstname]]],
    ]]], JSON_THROW_ON_ERROR);
}

it('prepares a new mode-0600 token file before any refresh request', function () {
    $directory = sys_get_temp_dir().'/mautic-live-token-'.bin2hex(random_bytes(6));
    mkdir($directory);
    $path = $directory.'/rotated.json';
    $requests = 0;
    $output = RotatedTokenOutput::prepare($path);
    $requests++;
    $output->persist(new OAuthToken('access', 'refresh', 1_900_000_000), 'live');
    $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    expect($requests)->toBe(1)
        ->and(fileperms($path) & 0777)->toBe(0600)
        ->and($decoded)->toBe([
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'expires_at' => 1_900_000_000,
            'token_type' => 'bearer',
        ]);
    unlink($path);
    rmdir($directory);
});

it('fails preparation without sending refresh for existing unwritable or insecure destinations', function (string $case) {
    $path = tempnam(sys_get_temp_dir(), 'mautic-live-existing-');
    expect($path)->toBeString();
    $requests = 0;

    try {
        if ($case === 'existing') {
            RotatedTokenOutput::prepare($path);
        } elseif ($case === 'unwritable') {
            RotatedTokenOutput::prepare($path.'.blocked', open: static fn (): false => false);
        } else {
            unlink($path);
            RotatedTokenOutput::prepare($path, restrict: static fn (): false => false);
        }
        $requests++;
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->not->toContain('access', 'refresh', 'token-value');
    }

    expect($requests)->toBe(0);
    if (is_file($path)) {
        unlink($path);
    }
})->with(['existing', 'unwritable', 'permission verification']);

it('reports partial failed or unflushed rotated-token writes as uncertain', function (string $case) {
    $path = tempnam(sys_get_temp_dir(), 'mautic-live-output-');
    expect($path)->toBeString();
    unlink($path);
    $output = RotatedTokenOutput::prepare($path);
    $write = match ($case) {
        'partial' => static fn (mixed $handle, string $payload): int => strlen($payload) - 1,
        'failed' => static fn (): false => false,
        default => null,
    };
    $flush = $case === 'unflushed' ? static fn (): false => false : null;

    expect(fn () => $output->persist(
        new OAuthToken('access-token-value', 'refresh-token-value', 1_900_000_000),
        'live',
        $write,
        $flush,
    ))->toThrow(fn (OAuthTokenPersistenceUncertain $exception) => $exception->connection === 'live'
        && ! str_contains($exception->getMessage(), 'token-value'));
    unlink($path);
})->with(['partial', 'failed', 'unflushed']);

it('matches only a signed exact fixture update in complete JSONL records', function () {
    $matcher = new LiveWebhookCaptureMatcher;
    $email = 'lm-live-test.fixture@example.test';
    $valid = captureLine(updateBody(123, $email, 'WebhookAfter'));
    $batch = captureLine(json_encode(['mautic.lead_post_save_update' => [
        ['lead' => ['id' => 999, 'fields' => ['all' => ['email' => 'other@example.test', 'firstname' => 'Other']]]],
        ['lead' => ['id' => 123, 'fields' => ['all' => ['email' => $email, 'firstname' => 'WebhookAfter']]]],
    ]], JSON_THROW_ON_ERROR));

    expect($matcher->containsExpectedUpdate(captureLine(updateBody(1234, $email, 'WebhookAfter')), 'secret', 123, $email, 'firstname', 'WebhookAfter'))->toBeFalse()
        ->and($matcher->containsExpectedUpdate(captureLine(json_encode(['other' => [['nested' => ['id' => 123]]]], JSON_THROW_ON_ERROR)), 'secret', 123, $email, 'firstname', 'WebhookAfter'))->toBeFalse()
        ->and($matcher->containsExpectedUpdate(captureLine(updateBody(123, $email, 'WebhookAfter', 'mautic.lead_post_save_new')), 'secret', 123, $email, 'firstname', 'WebhookAfter'))->toBeFalse()
        ->and($matcher->containsExpectedUpdate(captureLine(updateBody(123, $email, 'WebhookBefore')), 'secret', 123, $email, 'firstname', 'WebhookAfter'))->toBeFalse()
        ->and($matcher->containsExpectedUpdate(captureLine(updateBody(123, 'other@example.test', 'WebhookAfter')), 'secret', 123, $email, 'firstname', 'WebhookAfter'))->toBeFalse()
        ->and($matcher->containsExpectedUpdate(str_replace('signature', 'signature-broken', $valid), 'secret', 123, $email, 'firstname', 'WebhookAfter'))->toBeFalse()
        ->and($matcher->containsExpectedUpdate($batch, 'secret', 123, $email, 'firstname', 'WebhookAfter'))->toBeTrue()
        ->and($matcher->containsExpectedUpdate($valid.'{"partial":', 'secret', 123, $email, 'firstname', 'WebhookAfter'))->toBeTrue()
        ->and($matcher->containsExpectedUpdate(rtrim($valid, "\n"), 'secret', 123, $email, 'firstname', 'WebhookAfter'))->toBeFalse();
});
