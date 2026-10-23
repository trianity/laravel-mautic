<?php

declare(strict_types=1);

use Trianity\LaravelMautic\Tests\Support\OAuthSubscriptionBurstControl;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$path = $argv[1] ?? '';
$worker = $argv[2] ?? '';
$deadlineMilliseconds = (int) ($argv[3] ?? 0);
$control = new OAuthSubscriptionBurstControl($path);
$control->workerReady($worker);
$processed = 0;
$deadline = hrtime(true) + ($deadlineMilliseconds * 1_000_000);

try {
    while (true) {
        while ($processed < $control->producedArrivals()) {
            $processed++;
            $control->event('arrival_processed', $worker);
        }
        if ($control->stopIsRequested() || ($control->productionIsComplete()
            && $processed === $control->producedArrivals())) {
            break;
        }
        if (hrtime(true) >= $deadline) {
            $control->event('worker_deadline', $worker);
            throw new RuntimeException('Subscription burst worker deadline exhausted.');
        }
        usleep(5_000);
    }
} finally {
    $control->workerDone($worker);
}
