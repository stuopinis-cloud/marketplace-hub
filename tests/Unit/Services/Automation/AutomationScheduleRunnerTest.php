<?php

namespace Tests\Unit\Services\Automation;

use App\Enums\SyncJobStatus;
use App\Models\AutomationSchedule;
use App\Models\SyncJob;
use App\Services\Automation\AutomationScheduleRunner;
use App\Services\Automation\DailyMarketplaceSync;
use App\Services\Automation\DailyMarketplaceSyncResult;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
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
        $this->mock(DailyMarketplaceSync::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('run');
        });

        $this->createSchedule([
            'enabled' => false,
            'next_run_at' => now()->subMinute(),
        ]);

        app(AutomationScheduleRunner::class)->runDueSchedules();

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

    public function test_enabled_due_schedule_runs_and_updates_timestamps(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 04:00:00', 'Europe/Vilnius'));

        $this->mock(DailyMarketplaceSync::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(function (
                    bool $runShopifyImport,
                    bool $runSupplierSync,
                    bool $runReadinessRefresh,
                    bool $runVarleExport,
                    bool $generateFailedCsv,
                ): bool {
                    return $runShopifyImport === true
                        && $runSupplierSync === false
                        && $runReadinessRefresh === true
                        && $runVarleExport === true
                        && $generateFailedCsv === true;
                })
                ->andReturn(DailyMarketplaceSyncResult::success());
        });

        $schedule = $this->createSchedule([
            'next_run_at' => now()->subMinute(),
        ]);

        $result = app(AutomationScheduleRunner::class)->runSchedule($schedule);
        $schedule->refresh();

        $this->assertSame('success', $result->status);
        $this->assertNotNull($schedule->last_run_at);
        $this->assertSame('success', $schedule->last_status);
        $this->assertNull($schedule->last_error);
        $this->assertNotNull($schedule->next_run_at);
        $this->assertTrue($schedule->next_run_at->greaterThan(now()));
    }

    public function test_schedule_not_due_does_not_run(): void
    {
        $this->mock(DailyMarketplaceSync::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('run');
        });

        $schedule = $this->createSchedule([
            'next_run_at' => now()->addHour(),
        ]);

        $result = app(AutomationScheduleRunner::class)->runSchedule($schedule);

        $this->assertSame('skipped', $result->status);
        $this->assertStringContainsString('not due', (string) $result->message);
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
        SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Running,
            'started_at' => now(),
        ]);

        $this->mock(DailyMarketplaceSync::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('run');
        });

        $schedule = $this->createSchedule([
            'next_run_at' => now()->subMinute(),
        ]);

        $result = app(AutomationScheduleRunner::class)->runSchedule($schedule);
        $schedule->refresh();

        $this->assertSame('blocked', $result->status);
        $this->assertSame('blocked', $schedule->last_status);
        $this->assertStringContainsString('still running', (string) $schedule->last_error);
    }

    public function test_failed_run_stores_last_error_and_next_run_at(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 04:00:00', 'Europe/Vilnius'));

        $this->mock(DailyMarketplaceSync::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->andReturn(DailyMarketplaceSyncResult::failed('Varle export failed hard.'));
        });

        $schedule = $this->createSchedule([
            'next_run_at' => now()->subMinute(),
        ]);

        $result = app(AutomationScheduleRunner::class)->runSchedule($schedule);
        $schedule->refresh();

        $this->assertSame('failed', $result->status);
        $this->assertSame('failed', $schedule->last_status);
        $this->assertSame('Varle export failed hard.', $schedule->last_error);
        $this->assertNotNull($schedule->next_run_at);
        $this->assertTrue($schedule->next_run_at->greaterThan(now()));
    }

    public function test_run_due_schedules_processes_due_enabled_schedules(): void
    {
        $this->mock(DailyMarketplaceSync::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->once()->andReturn(DailyMarketplaceSyncResult::success());
        });

        $schedule = $this->createSchedule([
            'next_run_at' => now()->subMinute(),
        ]);

        app(AutomationScheduleRunner::class)->runDueSchedules();

        $schedule->refresh();
        $this->assertSame('success', $schedule->last_status);
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
