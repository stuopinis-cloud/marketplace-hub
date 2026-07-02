<?php

namespace Tests\Unit\Services\Marketplace\Varle;

use App\Enums\ProductStatus;
use App\Enums\SyncJobItemStatus;
use App\Enums\SyncJobStatus;
use App\Models\MarketplaceChannel;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Source;
use App\Models\SyncJob;
use App\Models\SyncJobItem;
use App\Services\Marketplace\Varle\VarleReadinessMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VarleReadinessMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_jobs_are_resolved_by_type_and_channel(): void
    {
        SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Completed,
        ]);

        $latestImport = SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Partial,
        ]);

        SyncJob::query()->create([
            'type' => 'export',
            'channel' => 'varle',
            'status' => SyncJobStatus::Completed,
        ]);

        $latestExport = SyncJob::query()->create([
            'type' => 'export',
            'channel' => 'varle',
            'status' => SyncJobStatus::Partial,
            'context' => ['exported_variants' => 10],
        ]);

        $metrics = $this->app->make(VarleReadinessMetrics::class);

        $this->assertSame($latestImport->id, $metrics->latestShopifyImport()?->id);
        $this->assertSame($latestExport->id, $metrics->latestVarleExport()?->id);
        $this->assertSame(10, $metrics->exportedVariantsCount($latestExport));
    }

    public function test_data_quality_counts_catalog_issues(): void
    {
        MarketplaceChannel::query()->create([
            'name' => 'Varle.lt',
            'type' => 'varle',
            'enabled' => true,
            'config' => ['default_category' => 'Kita'],
        ]);

        $source = Source::query()->create([
            'type' => 'shopify',
            'name' => 'Shopify',
            'enabled' => true,
            'config' => [],
        ]);

        $published = Product::query()->create([
            'source_id' => $source->id,
            'external_id' => 'pub-1',
            'title' => 'Published',
            'status' => ProductStatus::Active,
            'product_type' => 'Shoes',
            'imported_at' => now(),
        ]);

        Product::query()->create([
            'source_id' => $source->id,
            'external_id' => 'draft-1',
            'title' => 'Draft',
            'status' => ProductStatus::Draft,
            'imported_at' => now(),
        ]);

        ProductVariant::query()->create([
            'product_id' => $published->id,
            'external_id' => 'v-1',
            'sku' => null,
            'barcode' => null,
            'price' => 0,
        ]);

        $quality = $this->app->make(VarleReadinessMetrics::class)->dataQuality();

        $this->assertSame(1, $quality['published_products']);
        $this->assertSame(1, $quality['unpublished_products']);
        $this->assertSame(1, $quality['total_variants']);
        $this->assertSame(1, $quality['variants_missing_barcode']);
        $this->assertSame(1, $quality['variants_missing_sku']);
        $this->assertSame(1, $quality['variants_with_invalid_price']);
        $this->assertSame(2, $quality['products_missing_images']);
    }

    public function test_recent_export_problems_returns_latest_items_for_export_job(): void
    {
        $export = SyncJob::query()->create([
            'type' => 'export',
            'channel' => 'varle',
            'status' => SyncJobStatus::Partial,
        ]);

        $otherExport = SyncJob::query()->create([
            'type' => 'export',
            'channel' => 'varle',
            'status' => SyncJobStatus::Completed,
        ]);

        SyncJobItem::query()->create([
            'sync_job_id' => $export->id,
            'sku' => 'OLD-SKU',
            'status' => SyncJobItemStatus::Failed,
            'message' => 'Old problem',
        ]);

        SyncJobItem::query()->create([
            'sync_job_id' => $otherExport->id,
            'sku' => 'OTHER-SKU',
            'status' => SyncJobItemStatus::Failed,
            'message' => 'Other export',
        ]);

        $problems = $this->app->make(VarleReadinessMetrics::class)->recentExportProblems();

        $this->assertCount(1, $problems);
        $this->assertSame('OTHER-SKU', $problems->first()->sku);
    }
}
