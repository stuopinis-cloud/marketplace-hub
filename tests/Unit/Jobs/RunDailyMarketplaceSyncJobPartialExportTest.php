<?php

namespace Tests\Unit\Jobs;

use App\Enums\SyncJobStatus;
use App\Jobs\RunDailyMarketplaceSyncJob;
use App\Models\SyncJob;
use App\Services\Automation\DailyMarketplaceSync;
use App\Services\Automation\DailyMarketplaceSyncResult;
use App\Services\Sync\MarketplaceJobLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\TestCase;

class RunDailyMarketplaceSyncJobPartialExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_sync_with_varle_completed_does_not_fail_queue(): void
    {
        $syncJob = $this->createDailySyncJob();

        $this->mock(DailyMarketplaceSync::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->once()->andReturn(DailyMarketplaceSyncResult::success([
                'varle_export' => [
                    'sync_job_id' => 10,
                    'exported_variants' => 100,
                    'skipped_variants' => 0,
                    'status' => 'completed',
                ],
            ]));
        });

        (new RunDailyMarketplaceSyncJob($syncJob->id))->handle(app(DailyMarketplaceSync::class));

        $syncJob->refresh();
        $this->assertSame(SyncJobStatus::Completed, $syncJob->status);
        $this->assertNull($syncJob->error_message);
        $this->assertSame('finished', data_get($syncJob->context, 'stage'));
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertFalse(MarketplaceJobLock::isLocked(MarketplaceJobLock::MARKETPLACE_DAILY_SYNC));
    }

    public function test_daily_sync_with_varle_partial_does_not_fail_queue(): void
    {
        $syncJob = $this->createDailySyncJob();

        $this->mock(DailyMarketplaceSync::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->once()->andReturn(DailyMarketplaceSyncResult::partial(
                'Daily marketplace sync completed with Varle export warnings.',
                [
                    'varle_export' => [
                        'sync_job_id' => 11,
                        'exported_variants' => 8306,
                        'skipped_variants' => 4302,
                        'status' => 'partial',
                        'warning' => 'Varle export finished with skipped variants.',
                        'public_url' => 'https://hub.gudle.lt/feeds/varle.xml',
                    ],
                    'failed_csv' => [
                        'sync_job_id' => 11,
                        'path' => 'exports/failed-11.csv',
                        'url' => 'https://hub.gudle.lt/storage/exports/failed-11.csv',
                    ],
                ],
                ['Varle export finished with skipped variants.'],
            ));
        });

        (new RunDailyMarketplaceSyncJob($syncJob->id))->handle(app(DailyMarketplaceSync::class));

        $syncJob->refresh();
        $this->assertSame(SyncJobStatus::Partial, $syncJob->status);
        $this->assertNull($syncJob->error_message);
        $this->assertSame('finished_with_warnings', data_get($syncJob->context, 'stage'));
        $this->assertSame('partial', data_get($syncJob->context, 'outcome'));
        $this->assertSame('Varle export finished with skipped variants.', data_get($syncJob->context, 'warning'));
        $this->assertSame(4302, data_get($syncJob->context, 'varle_skipped_variants'));
        $this->assertSame(8306, data_get($syncJob->context, 'varle_exported_variants'));
        $this->assertSame('exports/failed-11.csv', data_get($syncJob->context, 'failed_csv.path'));
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, SyncJob::query()->where('status', SyncJobStatus::Running)->count());
        $this->assertFalse(MarketplaceJobLock::isLocked(MarketplaceJobLock::MARKETPLACE_DAILY_SYNC));
    }

    public function test_daily_sync_with_varle_failed_fails_queue_job(): void
    {
        $syncJob = $this->createDailySyncJob();

        $this->mock(DailyMarketplaceSync::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->once()->andReturn(DailyMarketplaceSyncResult::failed(
                'Varle export failed: no variants were exported.',
                [
                    'varle_export' => [
                        'exported_variants' => 0,
                        'skipped_variants' => 50,
                        'status' => 'failed',
                    ],
                ],
            ));
        });

        try {
            (new RunDailyMarketplaceSyncJob($syncJob->id))->handle(app(DailyMarketplaceSync::class));
            $this->fail('Expected RuntimeException for failed Varle export.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Varle export failed: no variants were exported.', $exception->getMessage());
        }

        $syncJob->refresh();
        $this->assertSame(SyncJobStatus::Failed, $syncJob->status);
        $this->assertSame('Varle export failed: no variants were exported.', $syncJob->error_message);
        $this->assertSame('failed', data_get($syncJob->context, 'stage'));
    }

    private function createDailySyncJob(): SyncJob
    {
        return SyncJob::query()->create([
            'type' => 'daily_sync',
            'source' => 'marketplace',
            'status' => SyncJobStatus::Pending,
            'context' => ['stage' => 'queued'],
        ]);
    }
}
