<?php

namespace Tests\Unit\Services\Suppliers;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Source;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Suppliers\SupplierSkuMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierSkuGlobalMatchingTest extends TestCase
{
    use RefreshDatabase;

    public function test_sku_global_matches_by_sku_even_when_vendor_differs(): void
    {
        $variant = $this->createVariant('Other Vendor', 'PREZ-100');

        $matcher = new SupplierSkuMatcher(
            vendorScope: [],
            matchingStrategy: SupplierSkuMatcher::STRATEGY_SKU_GLOBAL,
            matchByBarcode: false,
        );

        $match = $matcher->match('PREZ-100', $matcher->loadShopifyVariants(), collect());

        $this->assertSame(SupplierProduct::MATCH_STATUS_MATCHED, $match['match_status']);
        $this->assertSame(SupplierProduct::MATCH_METHOD_SKU_GLOBAL, $match['match_method']);
        $this->assertSame($variant->id, $match['variant']?->id);
    }

    public function test_sku_global_does_not_require_vendor_scope(): void
    {
        $this->createVariant('Whatever', 'GLOBAL-SKU-1');

        $matcher = new SupplierSkuMatcher(
            vendorScope: [],
            matchingStrategy: SupplierSkuMatcher::STRATEGY_SKU_GLOBAL,
            matchByBarcode: false,
        );

        $variants = $matcher->loadShopifyVariants();

        $this->assertNotEmpty($variants);
        $this->assertTrue($variants->contains(fn (ProductVariant $variant): bool => $variant->sku === 'GLOBAL-SKU-1'));
    }

    public function test_sku_global_marks_duplicate_sku_as_ambiguous(): void
    {
        $this->createVariant('Vendor A', 'DUP-SKU');
        $this->createVariant('Vendor B', 'DUP-SKU');

        $matcher = new SupplierSkuMatcher(
            vendorScope: [],
            matchingStrategy: SupplierSkuMatcher::STRATEGY_SKU_GLOBAL,
            matchByBarcode: false,
        );

        $match = $matcher->match('DUP-SKU', $matcher->loadShopifyVariants(), collect());

        $this->assertSame(SupplierProduct::MATCH_STATUS_AMBIGUOUS, $match['match_status']);
        $this->assertNull($match['variant']);
        $this->assertSame('duplicate_shopify_sku', $match['issue_code']);
    }

    public function test_sku_global_does_not_barcode_match_unless_enabled(): void
    {
        $barcodeVariant = $this->createVariant('Vendor A', 'OTHER-SKU', '5901234123457');
        $this->createVariant('Vendor B', 'FEED-SKU', '9999999999999');

        $disabled = new SupplierSkuMatcher(
            vendorScope: [],
            matchingStrategy: SupplierSkuMatcher::STRATEGY_SKU_GLOBAL,
            matchByBarcode: false,
        );

        $withoutBarcode = $disabled->match('FEED-SKU', $disabled->loadShopifyVariants(), collect(), '5901234123457');
        $this->assertSame(SupplierProduct::MATCH_METHOD_SKU_GLOBAL, $withoutBarcode['match_method']);
        $this->assertNotSame($barcodeVariant->id, $withoutBarcode['variant']?->id);

        $enabled = new SupplierSkuMatcher(
            vendorScope: [],
            matchingStrategy: SupplierSkuMatcher::STRATEGY_SKU_GLOBAL,
            matchByBarcode: true,
        );

        $withBarcode = $enabled->match('FEED-SKU', $enabled->loadShopifyVariants(), collect(), '5901234123457');
        $this->assertSame(SupplierProduct::MATCH_METHOD_BARCODE, $withBarcode['match_method']);
        $this->assertSame($barcodeVariant->id, $withBarcode['variant']?->id);
    }

    public function test_scoped_default_still_requires_vendor_scope(): void
    {
        $this->createVariant('Helikon-Tex', 'SCOPED-1');

        $matcher = new SupplierSkuMatcher(
            vendorScope: [],
            matchingStrategy: SupplierSkuMatcher::STRATEGY_SCOPED_DEFAULT,
            matchByBarcode: true,
        );

        $this->assertCount(0, $matcher->loadShopifyVariants());
    }

    public function test_for_supplier_reads_sku_global_config(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Prezioso',
            'code' => Supplier::CODE_PREZIOSO,
            'enabled' => true,
            'connector_type' => Supplier::CONNECTOR_CSV_URL,
            'config' => [
                'matching_strategy' => 'sku_global',
                'match_by_barcode' => false,
                'vendor_scope' => [],
            ],
        ]);

        $matcher = SupplierSkuMatcher::forSupplier($supplier);

        $this->assertSame(SupplierSkuMatcher::STRATEGY_SKU_GLOBAL, $matcher->matchingStrategy());
    }

    private function createVariant(string $vendor, string $sku, ?string $barcode = null): ProductVariant
    {
        $source = Source::query()->firstOrCreate(
            ['type' => 'shopify', 'name' => 'Shopify'],
            ['enabled' => true, 'config' => []],
        );

        $product = Product::query()->create([
            'source_id' => $source->id,
            'external_id' => 'product-'.uniqid(),
            'title' => 'Product '.$sku,
            'vendor' => $vendor,
            'status' => ProductStatus::Active,
            'imported_at' => now(),
        ]);

        return ProductVariant::query()->create([
            'product_id' => $product->id,
            'external_id' => 'variant-'.uniqid(),
            'sku' => $sku,
            'barcode' => $barcode,
            'price' => 10,
        ]);
    }
}
