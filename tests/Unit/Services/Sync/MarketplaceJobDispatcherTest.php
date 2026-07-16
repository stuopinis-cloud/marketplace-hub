<?php

namespace Tests\Unit\Services\Sync;

use App\Enums\SyncJobStatus;
use App\Jobs\GenerateVarleXmlJob;
use App\Jobs\ImportShopifyProductsJob;
use App\Jobs\RunDailyMarketplaceSyncJob;
use App\Models\SyncJob;
use App\Services\Marketplace\Varle\VarleFeedPublisher;
use App\Services\Marketplace\Varle\VarleExportResult;
use App\Services\Shopify\ShopifyProductImporter;
use App\Services\Shopify\ShopifyImportResult;
use App\Services\Sync\MarketplaceJobDispatcher;
use App\Services\Sync\MarketplaceJobLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery\MockInterface;
use Tests\TestCase;

class MarketplaceJobDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_shopify_import_dispatch_is_blocked_when_running_job_exists(): void
    {
        Bus::fake();

        SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Running,
            'started_at' => now(),
        ]);

        $result = app(MarketplaceJobDispatcher::class)->dispatchShopifyImport();

        $this->assertTrue($result->alreadyRunning);
        Bus::assertNotDispatched(ImportShopifyProductsJob::class);
    }

    public function test_varle_export_dispatch_is_blocked_when_running_job_exists(): void
    {
        Bus::fake();

        SyncJob::query()->create([
            'type' => 'export',
            'channel' => 'varle',
            'status' => SyncJobStatus::Running,
            'started_at' => now(),
        ]);

        $result = app(MarketplaceJobDispatcher::class)->dispatchVarleExport();

        $this->assertTrue($result->alreadyRunning);
        Bus::assertNotDispatched(GenerateVarleXmlJob::class);
    }

    public function test_import_job_releases_lock_and_does_not_leave_running_status(): void
    {
        $this->mock(ShopifyProductImporter::class, function (MockInterface $mock): void {
            $mock->shouldReceive('import')->once()->andReturn(new ShopifyImportResult(
                syncJobId: 1,
                productsImported: 1,
                variantsImported: 1,
                failedItems: 0,
                newProductsCount: 0,
                updatedProductsCount: 1,
                pendingReviewProductsCount: 0,
                unpublishedProductsCount: 0,
            ));
        });

        (new ImportShopifyProductsJob)->handle(app(ShopifyProductImporter::class));

        $this->assertFalse(MarketplaceJobLock::isLocked(MarketplaceJobLock::SHOPIFY_IMPORT));
    }

    public function test_export_job_releases_lock_after_success(): void
    {
        $this->mock(VarleFeedPublisher::class, function (MockInterface $mock): void {
            $mock->shouldReceive('publish')->once()->andReturn(new VarleExportResult(
                syncJobId: 1,
                exportedVariants: 1,
                skippedVariants: 0,
                feedPath: 'feeds/varle.xml',
                publicUrl: 'http://example.test/feeds/varle.xml',
            ));
        });

        (new GenerateVarleXmlJob)->handle(app(VarleFeedPublisher::class));

        $this->assertFalse(MarketplaceJobLock::isLocked(MarketplaceJobLock::VARLE_EXPORT));
    }

    public function test_daily_sync_job_finalizes_completed(): void
    {
        $syncJob = SyncJob::query()->create([
            'type' => 'daily_sync',
            'source' => 'marketplace',
            'status' => SyncJobStatus::Pending,
            'context' => ['stage' => 'queued'],
        ]);

        $this->mock(\App\Services\Automation\DailyMarketplaceSync::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->once()->andReturn(
                \App\Services\Automation\DailyMarketplaceSyncResult::success(['ok' => true]),
            );
        });

        (new RunDailyMarketplaceSyncJob($syncJob->id))->handle(app(\App\Services\Automation\DailyMarketplaceSync::class));

        $syncJob->refresh();
        $this->assertSame(SyncJobStatus::Completed, $syncJob->status);
        $this->assertNotNull($syncJob->finished_at);
        $this->assertNotNull($syncJob->heartbeat_at);
        $this->assertNotNull($syncJob->process_id);
        $this->assertSame('finished', data_get($syncJob->context, 'stage'));
        $this->assertFalse(MarketplaceJobLock::isLocked(MarketplaceJobLock::MARKETPLACE_DAILY_SYNC));
    }

    public function test_daily_sync_job_finalizes_failed_on_exception(): void
    {
        $syncJob = SyncJob::query()->create([
            'type' => 'daily_sync',
            'source' => 'marketplace',
            'status' => SyncJobStatus::Pending,
        ]);

        $this->mock(\App\Services\Automation\DailyMarketplaceSync::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->once()->andThrow(new \RuntimeException('Pipeline exploded'));
        });

        try {
            (new RunDailyMarketplaceSyncJob($syncJob->id))->handle(app(\App\Services\Automation\DailyMarketplaceSync::class));
        } catch (\RuntimeException) {
        }

        $syncJob->refresh();
        $this->assertSame(SyncJobStatus::Failed, $syncJob->status);
        $this->assertNotNull($syncJob->finished_at);
        $this->assertSame('Pipeline exploded', $syncJob->error_message);
        $this->assertSame(0, SyncJob::query()->where('status', SyncJobStatus::Running)->count());
    }
}
