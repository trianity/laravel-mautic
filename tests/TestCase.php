<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Trianity\LaravelMautic\MauticServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));
    }

    protected function getPackageProviders($app): array
    {
        return [MauticServiceProvider::class];
    }
}
