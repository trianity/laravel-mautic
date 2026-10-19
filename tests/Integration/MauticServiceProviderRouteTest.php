<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Tests\Integration;

use Illuminate\Routing\Route;
use Orchestra\Testbench\TestCase;
use Trianity\LaravelMautic\MauticServiceProvider;

final class IsolatedHostRouteAction
{
    public function __invoke(): string
    {
        return 'healthy';
    }
}

final class MauticServiceProviderRouteTest extends TestCase
{
    public function test_registering_and_booting_the_provider_does_not_modify_host_routes(): void
    {
        self::assertNull($this->app->getProvider(MauticServiceProvider::class));

        $this->app['router']->get('/host/health', IsolatedHostRouteAction::class)
            ->middleware(['web', 'host.audit'])
            ->name('host.health');
        $baseline = $this->routeDefinitions();

        $provider = new MauticServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        self::assertSame($baseline, $this->routeDefinitions());
    }

    /**
     * @return list<array{
     *     methods: list<string>,
     *     uri: string,
     *     name: ?string,
     *     action: string,
     *     middleware: list<string>
     * }>
     */
    private function routeDefinitions(): array
    {
        $definitions = array_map(
            static fn (Route $route): array => [
                'methods' => array_values($route->methods()),
                'uri' => $route->uri(),
                'name' => $route->getName(),
                'action' => $route->getActionName(),
                'middleware' => array_values($route->middleware()),
            ],
            $this->app['router']->getRoutes()->getRoutes(),
        );
        usort($definitions, static fn (array $left, array $right): int => strcmp(
            implode('|', $left['methods']).'|'.$left['uri'],
            implode('|', $right['methods']).'|'.$right['uri'],
        ));

        return $definitions;
    }
}
