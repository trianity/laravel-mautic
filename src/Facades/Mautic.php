<?php

declare(strict_types=1);

namespace Trianity\LaravelMautic\Facades;

use Illuminate\Support\Facades\Facade;
use Trianity\LaravelMautic\MauticConnection;
use Trianity\LaravelMautic\MauticManager;

/**
 * @method static MauticConnection connection(?string $name = null)
 * @method static void purge(?string $name = null)
 * @method static void forgetConnections()
 *
 * @see MauticManager
 */
final class Mautic extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MauticManager::class;
    }
}
