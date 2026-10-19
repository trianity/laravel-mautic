<?php

declare(strict_types=1);

use Trianity\LaravelMautic\Tests\Support\OAuthAcceptanceConfiguration;
use Trianity\LaravelMautic\Tests\Support\OAuthAcceptanceHarness;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$harness = new OAuthAcceptanceHarness(OAuthAcceptanceConfiguration::fromEnvironment());

fwrite(STDOUT, $harness->prepareAuthorization().PHP_EOL);
