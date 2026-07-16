<?php

namespace Tests\Feature\Console;

use App\Console\MarketplaceScheduleRegistrar;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class MarketplaceScheduleTest extends TestCase
{
    public function test_schedule_contains_marketplace_daily_sync_when_enabled(): void
    {
        config([
            'marketplace.daily_sync.enabled' => true,
            'marketplace.daily_sync.time' => '03:00',
            'marketplace.daily_sync.timezone' => 'Europe/Vilnius',
        ]);

        $schedule = new Schedule;
        MarketplaceScheduleRegistrar::register($schedule);

        $dailySync = $this->findScheduledEvent($schedule, 'marketplace:daily-sync');

        $this->assertNotNull($dailySync);
        $this->assertSame('0 3 * * *', $dailySync->expression);
        $this->assertSame('Europe/Vilnius', $dailySync->timezone);
        $this->assertTrue($dailySync->withoutOverlapping);
    }

    public function test_schedule_does_not_contain_marketplace_daily_sync_when_disabled(): void
    {
        config(['marketplace.daily_sync.enabled' => false]);

        $schedule = new Schedule;
        MarketplaceScheduleRegistrar::register($schedule);

        $this->assertNull($this->findScheduledEvent($schedule, 'marketplace:daily-sync'));
    }

    public function test_schedule_contains_sync_detect_stuck_every_five_minutes(): void
    {
        config(['marketplace.daily_sync.enabled' => true]);

        $schedule = new Schedule;
        MarketplaceScheduleRegistrar::register($schedule);

        $detectStuck = $this->findScheduledEvent($schedule, 'sync:detect-stuck');

        $this->assertNotNull($detectStuck);
        $this->assertSame('*/5 * * * *', $detectStuck->expression);
    }

    public function test_schedule_does_not_contain_duplicate_varle_export_command(): void
    {
        config(['marketplace.daily_sync.enabled' => true]);

        $schedule = new Schedule;
        MarketplaceScheduleRegistrar::register($schedule);

        $varleExports = collect($schedule->events())
            ->filter(fn ($event): bool => str_contains((string) ($event->command ?? ''), 'varle:export-xml'))
            ->values();

        $this->assertCount(0, $varleExports);
    }

    private function findScheduledEvent(Schedule $schedule, string $command): ?object
    {
        return collect($schedule->events())->first(
            fn ($event): bool => str_contains((string) ($event->command ?? ''), $command),
        );
    }
}
