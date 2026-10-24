<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Tests\Integration;

use Illuminate\Routing\Route;
use Orchestra\Testbench\TestCase;
use Trianity\LaravelMautic\Contracts\Clock;
use Trianity\LaravelMautic\Contracts\ContactMappingRepository;
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
    public function test_registration_preserves_host_overrides_and_their_lifetimes_without_eager_resolution(): void
    {
        $clock = $this->createStub(Clock::class);
        $mappings = $this->createStub(ContactMappingRepository::class);
        $clockResolutions = 0;
        $mappingResolutions = 0;

        $this->app->singleton(Clock::class, static function () use ($clock, &$clockResolutions): Clock {
            $clockResolutions++;

            return $clock;
        });
        $this->app->bind(ContactMappingRepository::class, static function () use ($mappings, &$mappingResolutions): ContactMappingRepository {
            $mappingResolutions++;

            return $mappings;
        });

        $provider = new MauticServiceProvider($this->app);
        $provider->register();

        self::assertFalse($this->app->resolved(Clock::class));
        self::assertFalse($this->app->resolved(ContactMappingRepository::class));
        self::assertSame(0, $clockResolutions);
        self::assertSame(0, $mappingResolutions);
        self::assertTrue($this->app->isShared(Clock::class));
        self::assertFalse($this->app->isShared(ContactMappingRepository::class));

        self::assertSame($clock, $this->app->make(Clock::class));
        self::assertSame($clock, $this->app->make(Clock::class));
        self::assertSame($mappings, $this->app->make(ContactMappingRepository::class));
        self::assertSame($mappings, $this->app->make(ContactMappingRepository::class));
        self::assertSame(1, $clockResolutions);
        self::assertSame(2, $mappingResolutions);
    }

    public function test_registration_keeps_default_bindings_lazy_with_their_declared_lifetimes(): void
    {
        $provider = new MauticServiceProvider($this->app);
        $provider->register();

        self::assertFalse($this->app->resolved(Clock::class));
        self::assertFalse($this->app->resolved(ContactMappingRepository::class));
        self::assertFalse($this->app->isShared(Clock::class));
        self::assertTrue($this->app->isShared(ContactMappingRepository::class));

        self::assertNotSame($this->app->make(Clock::class), $this->app->make(Clock::class));
        self::assertSame(
            $this->app->make(ContactMappingRepository::class),
            $this->app->make(ContactMappingRepository::class),
        );
    }

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
