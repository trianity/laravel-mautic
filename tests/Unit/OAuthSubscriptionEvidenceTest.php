<?php

declare(strict_types=1);

use Trianity\LaravelMautic\Tests\Support\OAuthSubscriptionEvidence;

it('persists sanitized authoritative and per-consumer burst request evidence', function () {
    $directory = sys_get_temp_dir().'/mautic-subscription-evidence-'.bin2hex(random_bytes(6));
    try {
        $evidence = new OAuthSubscriptionEvidence('owned-run', $directory);
        $evidence->requestCounts(7, 3);
        $evidence->consumerRequestCounts([
            'worker-1' => ['operation' => 2, 'cleanup' => 0],
            'parent' => ['operation' => 5, 'cleanup' => 3],
        ]);
        $path = $directory.'/subscription-'.hash('sha256', 'owned-run').'.json';
        $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        expect($payload['operation_request_attempts'])->toBe(7)
            ->and($payload['cleanup_request_attempts'])->toBe(3)
            ->and($payload['request_attempts_by_consumer'])->toBe([
                'parent' => ['operation' => 5, 'cleanup' => 3],
                'worker-1' => ['operation' => 2, 'cleanup' => 0],
            ]);
    } finally {
        if (isset($path)) {
            @unlink($path);
        }
        @rmdir($directory);
    }
});
