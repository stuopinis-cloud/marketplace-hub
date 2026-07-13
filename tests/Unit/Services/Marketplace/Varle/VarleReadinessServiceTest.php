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

    public function test_simple_product_readiness_reports_simple_export_structure(): void
    {
        $variant = VarleCatalogFixtures::createSimpleDefaultTitleProduct();
        $service = $this->app->make(VarleReadinessService::class);

        $analysis = $service->analyze($variant->product->fresh(['variants.inventoryLevels', 'images', 'sourceCategories']));

        $this->assertSame('simple_product', $analysis['export_structure']);
        $this->assertFalse($analysis['will_generate_variants_block']);
        $this->assertTrue($analysis['is_simple_shopify_product']);
        $this->assertSame([], $analysis['meaningful_options']);
        $this->assertSame(1, $analysis['shopify_total_variants']);
    }

    public function test_simple_product_without_variant_image_can_be_ready_with_gallery(): void
    {
        $variant = VarleCatalogFixtures::createSimpleDefaultTitleProduct(variantOverrides: ['image_url' => null]);
        $service = $this->app->make(VarleReadinessService::class);

        $analysis = $service->analyze($variant->product->fresh(['variants.inventoryLevels', 'images', 'sourceCategories']));

        $this->assertSame('has_fallback_images', $analysis['image_status']);
        $this->assertNotContains('missing_variant_image', $analysis['issue_codes']);
    }

    public function test_variant_product_readiness_reports_variant_export_structure(): void
    {
        $product = VarleCatalogFixtures::createSizeOnlyProduct();
        $service = $this->app->make(VarleReadinessService::class);

        $analysis = $service->analyze($product->fresh(['variants.inventoryLevels', 'images', 'sourceCategories']));

        $this->assertSame('variant_product', $analysis['export_structure']);
        $this->assertTrue($analysis['will_generate_variants_block']);
        $this->assertNotEmpty($analysis['meaningful_options']);
    }

    public function test_local_zero_and_supplier_positive_can_still_be_ready(): void
    {
        $supplier = \App\Models\Supplier::query()->create([
            'name' => 'M-Tac',
            'code' => 'mtac',
            'enabled' => true,
            'in_stock_delivery_text' => '5-10 d.d.',
            'stale_after_minutes' => 1800,
        ]);

        $variant = VarleCatalogFixtures::createSimpleDefaultTitleProduct(
            productOverrides: ['vendor' => 'M-Tac'],
        );
        $variant->inventoryLevels()->update(['quantity' => 0]);
        \App\Models\SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_variant_id' => $variant->id,
            'supplier_sku' => (string) $variant->sku,
            'stock_quantity' => 6,
            'match_status' => \App\Models\SupplierProduct::MATCH_STATUS_MATCHED,
            'match_method' => \App\Models\SupplierProduct::MATCH_METHOD_SKU,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        $service = $this->app->make(VarleReadinessService::class);
        $analysis = $service->analyze($variant->product->fresh(['variants.inventoryLevels', 'variants.supplierProducts.supplier', 'images', 'sourceCategories']));

        $this->assertGreaterThan(0, $analysis['exportable_variants_count']);
        $this->assertSame('supplier', $analysis['variant_diagnostics'][0]['availability_source']);
    }
}
