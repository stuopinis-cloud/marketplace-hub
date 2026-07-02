<?php

namespace Database\Seeders;

use App\Models\AutomationSchedule;
use App\Services\Automation\AutomationScheduleRunner;
use Illuminate\Database\Seeder;

class AutomationScheduleSeeder extends Seeder
{
    public function run(): void
    {
        $schedule = AutomationSchedule::query()->firstOrCreate(
            [
                'type' => 'daily_marketplace_sync',
                'name' => 'Daily Shopify → Varle Sync',
            ],
            [
                'enabled' => false,
                'frequency' => 'daily',
                'run_time' => '03:30:00',
                'timezone' => 'Europe/Vilnius',
                'run_shopify_import' => true,
                'run_varle_export' => true,
                'generate_failed_csv' => true,
                'config' => [],
            ],
        );

        if ($schedule->next_run_at === null && filled($schedule->run_time)) {
            app(AutomationScheduleRunner::class)->refreshNextRunAt($schedule);
        }
    }
}
