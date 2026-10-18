<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic;

use Illuminate\Support\ServiceProvider;
use Trianity\LaravelMautic\Contracts\ConnectionFactory;
use Trianity\LaravelMautic\Contracts\HttpClientFactory;
use Trianity\LaravelMautic\Http\GuzzleHttpClientFactory;
use Trianity\LaravelMautic\Support\ApiLibraryConnectionFactory;

final class MauticServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mautic.php', 'mautic');

        $this->app->bindIf(HttpClientFactory::class, GuzzleHttpClientFactory::class);
        $this->app->bindIf(ConnectionFactory::class, ApiLibraryConnectionFactory::class);
        $this->app->singleton(MauticManager::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/mautic.php' => config_path('mautic.php'),
            ], 'mautic-config');
        }
    }
}
