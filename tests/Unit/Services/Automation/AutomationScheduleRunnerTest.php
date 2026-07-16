<?php

namespace Tests\Unit\Services\Automation;

use App\Enums\SyncJobStatus;
use App\Jobs\RunDailyMarketplaceSyncJob;
use App\Models\AutomationSchedule;
use App\Models\SyncJob;
use App\Services\Automation\AutomationScheduleRunner;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class AutomationScheduleRunnerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_disabled_schedule_does_not_run_via_due_query(): void
    {
        Bus::fake();

        $this->createSchedule([
            'enabled' => false,
            'next_run_at' => now()->subMinute(),
        ]);

        app(AutomationScheduleRunner::class)->runDueSchedules();

        Bus::assertNotDispatched(RunDailyMarketplaceSyncJob::class);
        $this->assertDatabaseHas('automation_schedules', [
            'last_status' => null,
        ]);
    }

    public function test_disabled_schedule_run_now_is_skipped(): void
    {
        $schedule = $this->createSchedule([
            'enabled' => false,
            'next_run_at' => now()->subMinute(),
        ]);

        $result = app(AutomationScheduleRunner::class)->runSchedule($schedule);

        $this->assertSame('skipped', $result->status);
        $schedule->refresh();
        $this->assertSame('skipped', $schedule->last_status);
    }

    public function test_enabled_due_schedule_queues_daily_sync(): void
    {
        Bus::fake();
        Carbon::setTestNow(Carbon::parse('2026-07-01 04:00:00', 'Europe/Vilnius'));

        $schedule = $this->createSchedule([
            'next_run_at' => now()->subMinute(),
        ]);

        $result = app(AutomationScheduleRunner::class)->runSchedule($schedule);
        $schedule->refresh();

        $this->assertSame('success', $result->status);
        $this->assertSame('queued', $schedule->last_status);
        $this->assertNotNull($schedule->last_run_at);
        $this->assertNull($schedule->last_error);
        $this->assertNotNull($schedule->next_run_at);
        $this->assertTrue($schedule->next_run_at->greaterThan(now()));
        Bus::assertDispatched(RunDailyMarketplaceSyncJob::class);
    }

    public function test_schedule_not_due_does_not_run(): void
    {
        Bus::fake();

        $schedule = $this->createSchedule([
            'next_run_at' => now()->addHour(),
        ]);

        $result = app(AutomationScheduleRunner::class)->runSchedule($schedule);

        $this->assertSame('skipped', $result->status);
        $this->assertStringContainsString('not due', (string) $result->message);
        Bus::assertNotDispatched(RunDailyMarketplaceSyncJob::class);
    }

    public function test_next_run_at_is_calculated_from_run_time_and_timezone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 02:00:00', 'Europe/Vilnius'));

        $schedule = $this->createSchedule([
            'run_time' => '03:30:00',
            'timezone' => 'Europe/Vilnius',
        ]);

        $nextRunAt = app(AutomationScheduleRunner::class)->calculateNextRunAt($schedule);

        $this->assertSame(
            '2026-07-01 03:30:00',
            $nextRunAt->copy()->timezone('Europe/Vilnius')->format('Y-m-d H:i:s'),
        );
    }

    public function test_running_sync_job_blocks_schedule(): void
    {
        Bus::fake();

        SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Running,
            'started_at' => now(),
        ]);

        $schedule = $this->createSchedule([
            'next_run_at' => now()->subMinute(),
        ]);

        $result = app(AutomationScheduleRunner::class)->runSchedule($schedule);
        $schedule->refresh();

        $this->assertSame('blocked', $result->status);
        $this->assertSame('blocked', $schedule->last_status);
        $this->assertStringContainsString('still running', (string) $schedule->last_error);
        Bus::assertNotDispatched(RunDailyMarketplaceSyncJob::class);
    }

    public function test_already_running_daily_sync_blocks_schedule(): void
    {
        Bus::fake();

        SyncJob::query()->create([
            'type' => 'daily_sync',
            'source' => 'marketplace',
            'status' => SyncJobStatus::Running,
            'started_at' => now(),
        ]);

        $schedule = $this->createSchedule([
            'next_run_at' => now()->subMinute(),
        ]);

        $result = app(AutomationScheduleRunner::class)->runSchedule($schedule);
        $schedule->refresh();

        $this->assertSame('blocked', $result->status);
        Bus::assertNotDispatched(RunDailyMarketplaceSyncJob::class);
    }

    public function test_run_due_schedules_processes_due_enabled_schedules(): void
    {
        Bus::fake();

        $schedule = $this->createSchedule([
            'next_run_at' => now()->subMinute(),
        ]);

        app(AutomationScheduleRunner::class)->runDueSchedules();

        $schedule->refresh();
        $this->assertSame('queued', $schedule->last_status);
        Bus::assertDispatched(RunDailyMarketplaceSyncJob::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createSchedule(array $overrides = []): AutomationSchedule
    {
        return AutomationSchedule::query()->create(array_merge([
            'name' => 'Test Schedule',
            'type' => 'daily_marketplace_sync',
            'enabled' => true,
            'frequency' => 'daily',
            'run_time' => '03:30:00',
            'timezone' => 'Europe/Vilnius',
            'run_shopify_import' => true,
            'run_varle_export' => true,
            'generate_failed_csv' => true,
        ], $overrides));
    }
}
