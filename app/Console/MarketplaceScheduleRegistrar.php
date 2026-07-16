<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

class MarketplaceScheduleRegistrar
{
    public static function register(Schedule $schedule): void
    {
        if (config('marketplace.daily_sync.enabled', true)) {
            $dailySync = $schedule
                ->command('marketplace:daily-sync')
                ->dailyAt((string) config('marketplace.daily_sync.time', '03:00'))
                ->timezone((string) config('marketplace.daily_sync.timezone', 'Europe/Vilnius'))
                ->withoutOverlapping();

            self::applyOneServerIfSupported($dailySync);
        }

        $schedule
            ->command('sync:detect-stuck')
            ->everyFiveMinutes()
            ->withoutOverlapping();
    }

    private static function applyOneServerIfSupported(Event $event): void
    {
        if (! self::cacheDriverSupportsLocks()) {
            return;
        }

        $event->onOneServer();
    }

    private static function cacheDriverSupportsLocks(): bool
    {
        $store = (string) config('cache.default', 'database');
        $driver = (string) config("cache.stores.{$store}.driver", $store);

        return in_array($driver, ['redis', 'memcached', 'database', 'dynamodb', 'file'], true);
    }
}
