<?php

namespace Tests\Feature\Console;

use App\Models\AutomationSchedule;
use App\Services\Automation\DailyMarketplaceSync;
use App\Services\Automation\DailyMarketplaceSyncResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class DailySyncCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_sync_command_runs_enabled_steps(): void
    {
        $this->mock(DailyMarketplaceSync::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->andReturn(DailyMarketplaceSyncResult::success([
                    'shopify_import' => [
                        'sync_job_id' => 1,
                        'products_imported' => 2,
                        'variants_imported' => 3,
                        'failed_items' => 0,
                    ],
                    'varle_export' => [
                        'sync_job_id' => 2,
                        'exported_variants' => 3,
                        'skipped_variants' => 0,
                        'feed_path' => 'feeds/varle.xml',
                        'public_url' => 'https://example.test/feeds/varle.xml',
                    ],
                ]));
        });

        $this->artisan('marketplace:daily-sync')
            ->expectsOutputToContain('Starting daily marketplace sync')
            ->expectsOutputToContain('Shopify import')
            ->expectsOutputToContain('Varle export')
            ->assertSuccessful();
    }

    public function test_daily_sync_command_honors_skip_options(): void
    {
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
                    return $runShopifyImport === false
                        && $runSupplierSync === true
                        && $runReadinessRefresh === true
                        && $runVarleExport === false
                        && $generateFailedCsv === false;
                })
                ->andReturn(DailyMarketplaceSyncResult::success());
        });

        $this->artisan('marketplace:daily-sync --skip-import --skip-varle --skip-failed-csv')
            ->expectsOutputToContain('Shopify import: skipped')
            ->expectsOutputToContain('Varle export: skipped')
            ->expectsOutputToContain('Failed CSV: skipped')
            ->assertSuccessful();
    }

    public function test_run_due_schedules_command_processes_due_schedules(): void
    {
        $this->mock(DailyMarketplaceSync::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->once()->andReturn(DailyMarketplaceSyncResult::success());
        });

        AutomationSchedule::query()->create([
            'name' => 'Due schedule',
            'type' => 'daily_marketplace_sync',
            'enabled' => true,
            'frequency' => 'daily',
            'run_time' => '03:30:00',
            'timezone' => 'Europe/Vilnius',
            'run_shopify_import' => true,
            'run_varle_export' => true,
            'generate_failed_csv' => true,
            'next_run_at' => now()->subMinute(),
        ]);

        $this->artisan('marketplace:run-due-schedules')
            ->expectsOutputToContain('Due automation schedules processed')
            ->assertSuccessful();
    }
}
