<?php

namespace Tests\Unit\Services\Suppliers;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Source;
use App\Models\SupplierProduct;
use App\Services\Suppliers\SupplierSkuMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierSkuEanConflictTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_sku_with_conflicting_ean_is_flagged(): void
    {
        $source = Source::query()->create([
            'type' => 'shopify',
            'name' => 'Shopify',
            'enabled' => true,
            'config' => [],
        ]);

        $product = Product::query()->create([
            'source_id' => $source->id,
            'external_id' => 'p1',
            'title' => 'P',
            'vendor' => 'Vendor',
            'status' => 'active',
            'handle' => 'p1',
        ]);

        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'external_id' => 'v1',
            'sku' => 'SKU-1',
            'barcode' => '5901111111111',
            'title' => 'Default',
            'price' => 10,
        ]);

        $matcher = new SupplierSkuMatcher(
            vendorScope: [],
            matchingStrategy: SupplierSkuMatcher::STRATEGY_SKU_GLOBAL,
            matchByBarcode: true,
        );

        $result = $matcher->match(
            'SKU-1',
            collect([$variant]),
            collect(),
            '5902222222222',
        );

        $this->assertSame(SupplierProduct::MATCH_STATUS_AMBIGUOUS, $result['match_status']);
        $this->assertSame('sku_ean_conflict', $result['issue_code']);
        $this->assertNull($result['variant']);
    }

    public function test_same_ean_with_different_sku_matches_by_barcode(): void
    {
        $source = Source::query()->create([
            'type' => 'shopify',
            'name' => 'Shopify',
            'enabled' => true,
            'config' => [],
        ]);

        $product = Product::query()->create([
            'source_id' => $source->id,
            'external_id' => 'p2',
            'title' => 'P2',
            'vendor' => 'Vendor',
            'status' => 'active',
            'handle' => 'p2',
        ]);

        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'external_id' => 'v2',
            'sku' => 'SHOPIFY-SKU',
            'barcode' => '5903333333333',
            'title' => 'Default',
            'price' => 10,
        ]);

        $matcher = new SupplierSkuMatcher(
            vendorScope: [],
            matchingStrategy: SupplierSkuMatcher::STRATEGY_SKU_GLOBAL,
            matchByBarcode: true,
        );

        $result = $matcher->match(
            'SUPPLIER-OTHER-SKU',
            collect([$variant]),
            collect(),
            '5903333333333',
        );

        $this->assertSame(SupplierProduct::MATCH_STATUS_MATCHED, $result['match_status']);
        $this->assertSame(SupplierProduct::MATCH_METHOD_BARCODE, $result['match_method']);
        $this->assertTrue($result['variant']?->is($variant));
    }
}
