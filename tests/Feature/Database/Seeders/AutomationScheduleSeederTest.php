<?php

namespace Tests\Feature\Database\Seeders;

use App\Models\AutomationSchedule;
use Database\Seeders\AutomationScheduleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomationScheduleSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_default_disabled_schedule(): void
    {
        $this->seed(AutomationScheduleSeeder::class);

        $schedule = AutomationSchedule::query()->first();

        $this->assertNotNull($schedule);
        $this->assertSame('Daily Shopify → Varle Sync', $schedule->name);
        $this->assertSame('daily_marketplace_sync', $schedule->type);
        $this->assertFalse($schedule->enabled);
        $this->assertSame('daily', $schedule->frequency);
        $this->assertSame('Europe/Vilnius', $schedule->timezone);
        $this->assertTrue($schedule->run_shopify_import);
        $this->assertTrue($schedule->run_varle_export);
        $this->assertTrue($schedule->generate_failed_csv);
        $this->assertNotNull($schedule->next_run_at);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(AutomationScheduleSeeder::class);
        $this->seed(AutomationScheduleSeeder::class);

        $this->assertSame(1, AutomationSchedule::query()->count());
    }
}
