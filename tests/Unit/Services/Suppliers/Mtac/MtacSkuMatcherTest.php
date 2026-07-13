<?php

namespace Tests\Unit\Services\Suppliers\Mtac;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Source;
use App\Models\SupplierProduct;
use App\Services\Suppliers\Mtac\MtacSkuMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MtacSkuMatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_matches_mtac_vendor_variant_by_sku_case_insensitively(): void
    {
        $variant = $this->createMtacVariant('ABC-123');
        $matcher = new MtacSkuMatcher;

        $result = $matcher->match(' abc-123 ', collect([$variant]), collect());

        $this->assertSame($variant->id, $result['variant']?->id);
        $this->assertSame(SupplierProduct::MATCH_STATUS_MATCHED, $result['match_status']);
        $this->assertSame(SupplierProduct::MATCH_METHOD_SKU, $result['match_method']);
    }

    public function test_does_not_match_when_vendor_variant_is_not_in_scope(): void
    {
        $this->createVariantForVendor('Other Vendor', 'ABC-123');
        $matcher = new MtacSkuMatcher;

        $result = $matcher->match('ABC-123', collect(), collect());

        $this->assertNull($result['variant']);
        $this->assertSame(SupplierProduct::MATCH_STATUS_UNMATCHED, $result['match_status']);
    }

    public function test_duplicate_shopify_sku_is_ambiguous(): void
    {
        $first = $this->createMtacVariant('DUP-1');
        $second = $this->createMtacVariant('dup-1');
        $matcher = new MtacSkuMatcher;

        $result = $matcher->match('DUP-1', collect([$first, $second]), collect());

        $this->assertNull($result['variant']);
        $this->assertSame(SupplierProduct::MATCH_STATUS_AMBIGUOUS, $result['match_status']);
        $this->assertSame('duplicate_shopify_sku', $result['issue_code']);
    }

    public function test_detects_duplicate_supplier_skus_in_feed(): void
    {
        $matcher = new MtacSkuMatcher;

        $duplicates = $matcher->duplicateSupplierSkus([
            ['sku' => 'A'],
            ['sku' => 'a'],
            ['sku' => 'B'],
        ]);

        $this->assertSame(2, $duplicates['a']);
        $this->assertArrayNotHasKey('b', $duplicates);
    }

    private function createMtacVariant(string $sku): ProductVariant
    {
        return $this->createVariantForVendor(MtacSkuMatcher::VENDOR, $sku);
    }

    private function createVariantForVendor(string $vendor, string $sku): ProductVariant
    {
        $source = Source::query()->create([
            'type' => 'shopify',
            'name' => 'Shopify',
            'enabled' => true,
            'config' => [],
        ]);

        $product = Product::query()->create([
            'source_id' => $source->id,
            'external_id' => 'product-'.uniqid(),
            'title' => 'Product',
            'vendor' => $vendor,
            'status' => ProductStatus::Active,
            'imported_at' => now(),
        ]);

        return ProductVariant::query()->create([
            'product_id' => $product->id,
            'external_id' => 'variant-'.uniqid(),
            'sku' => $sku,
            'price' => 10,
        ]);
    }
}
