<?php

namespace Tests\Unit\Services\Automation;

use App\Services\Automation\DailyMarketplaceSync;
use App\Services\Marketplace\Varle\VarleExportResult;
use App\Services\Marketplace\Varle\VarleFeedPublisher;
use App\Services\Marketplace\Varle\VarleReadinessService;
use App\Services\Shopify\ShopifyImportResult;
use App\Services\Shopify\ShopifyProductImporter;
use App\Services\Suppliers\SupplierSyncManager;
use App\Services\Suppliers\SupplierSyncResult;
use App\Services\Sync\SyncJobFailedCsvExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class DailyMarketplaceSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_executes_ordered_publication_pipeline(): void
    {
        $this->mock(ShopifyProductImporter::class, function (MockInterface $mock): void {
            $mock->shouldReceive('import')->once()->andReturn(new ShopifyImportResult(1, 2, 3, 0));
        });

        $this->mock(SupplierSyncManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('syncPublicationSuppliers')->once()->andReturn([
                'mtac' => ['result' => new SupplierSyncResult(1, 1, 1, 0, 0, 0, 1, 0, 0, 0)],
                'helik' => ['result' => new SupplierSyncResult(2, 1, 1, 0, 0, 0, 1, 0, 0, 0)],
                'csv' => [],
            ]);
        });

        $this->mock(VarleReadinessService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('refreshAll')->once()->andReturn(4);
        });

        $this->mock(VarleFeedPublisher::class, function (MockInterface $mock): void {
            $mock->shouldReceive('publish')->once()->andReturn(new VarleExportResult(
                syncJobId: 3,
                exportedVariants: 5,
                skippedVariants: 0,
                feedPath: '/tmp/feeds/varle.xml',
                publicUrl: 'https://example.test/feeds/varle.xml',
            ));
        });

        $this->mock(SyncJobFailedCsvExporter::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolveSyncJob')->once()->andReturn(null);
        });

        $result = app(DailyMarketplaceSync::class)->run();

        $this->assertTrue($result->successful);
        $this->assertArrayHasKey('shopify_import', $result->summary);
        $this->assertArrayHasKey('supplier_sync', $result->summary);
        $this->assertArrayHasKey('readiness_refresh', $result->summary);
        $this->assertTrue($result->summary['varle_export']['published_atomically']);
    }
}
