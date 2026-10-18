<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Trianity\LaravelMautic\MauticServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [MauticServiceProvider::class];
    }
}
