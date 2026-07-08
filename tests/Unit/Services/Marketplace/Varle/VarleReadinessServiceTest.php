<?php

namespace Tests\Unit\Services\Marketplace\Varle;

use App\Services\Marketplace\Varle\VarleReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\VarleCatalogFixtures;
use Tests\TestCase;

class VarleReadinessServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_with_all_barcodes_reports_all_variants_have_barcode_status(): void
    {
        $variant = VarleCatalogFixtures::createExportableVariant();
        $service = $this->app->make(VarleReadinessService::class);

        $analysis = $service->analyze($variant->product->fresh(['variants.inventoryLevels', 'images', 'sourceCategories']));

        $this->assertSame('all_variants_have_barcode', $analysis['barcode_status']);
        $this->assertNotContains('missing_barcode', $analysis['issue_codes']);
    }

    public function test_product_with_missing_barcode_reports_issue(): void
    {
        $variant = VarleCatalogFixtures::createExportableVariant(variantOverrides: ['barcode' => null]);
        $service = $this->app->make(VarleReadinessService::class);

        $analysis = $service->analyze($variant->product->fresh(['variants.inventoryLevels', 'images', 'sourceCategories']));

        $this->assertContains('missing_barcode', $analysis['issue_codes']);
        $this->assertFalse($analysis['is_ready_for_varle']);
    }

    public function test_cache_persists_readiness_columns(): void
    {
        $variant = VarleCatalogFixtures::createExportableVariant();
        $product = $variant->product;
        $service = $this->app->make(VarleReadinessService::class);

        $service->cache($product->fresh(['variants.inventoryLevels', 'images', 'sourceCategories']));

        $product->refresh();
        $this->assertNotNull($product->varle_readiness_cached_at);
        $this->assertSame('all_variants_have_barcode', $product->varle_barcode_status);
    }
}
