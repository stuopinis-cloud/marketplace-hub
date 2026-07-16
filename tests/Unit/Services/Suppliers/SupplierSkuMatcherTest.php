<?php

namespace Tests\Unit\Services\Suppliers;

use App\Enums\ProductStatus;
use App\Models\InventoryLevel;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Source;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Marketplace\ProductAvailabilityResolver;
use App\Services\Suppliers\SupplierSkuMatcher as VendorSkuMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierSkuMatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_matches_helikon_tex_and_direct_action_vendors(): void
    {
        $helikon = $this->createVariant('Helikon-Tex', 'HEL-SKU');
        $direct = $this->createVariant('Direct-Action', 'DA-SKU');
        $matcher = new VendorSkuMatcher(['Helikon-Tex', 'Direct-Action']);

        $variants = $matcher->loadShopifyVariants();

        $this->assertTrue($variants->contains('id', $helikon->id));
        $this->assertTrue($variants->contains('id', $direct->id));

        $helikonMatch = $matcher->match('hel-sku', $variants, collect());
        $this->assertSame($helikon->id, $helikonMatch['variant']?->id);

        $directMatch = $matcher->match('da-sku', $variants, collect());
        $this->assertSame($direct->id, $directMatch['variant']?->id);
    }

    public function test_stock_priority_prefers_lower_priority_supplier(): void
    {
        $variant = $this->createVariant('Helikon-Tex', 'PRIORITY-SKU');

        InventoryLevel::query()->create([
            'variant_id' => $variant->id,
            'warehouse_name' => 'Shopify',
            'quantity' => 0,
        ]);

        $mtac = Supplier::query()->create([
            'name' => 'M-Tac',
            'code' => 'mtac',
            'enabled' => true,
            'stock_priority' => 200,
            'in_stock_delivery_text' => '5-10 d.d.',
            'stale_after_minutes' => 1800,
        ]);

        $helik = Supplier::query()->create([
            'name' => 'Helikon / Direct-Action',
            'code' => 'helik',
            'enabled' => true,
            'stock_priority' => 100,
            'in_stock_delivery_text' => '3-5 d.d.',
            'stale_after_minutes' => 1800,
        ]);

        SupplierProduct::query()->create([
            'supplier_id' => $mtac->id,
            'product_variant_id' => $variant->id,
            'supplier_sku' => 'PRIORITY-SKU',
            'stock_quantity' => 20,
            'match_status' => SupplierProduct::MATCH_STATUS_MATCHED,
            'match_method' => SupplierProduct::MATCH_METHOD_SKU,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        SupplierProduct::query()->create([
            'supplier_id' => $helik->id,
            'product_variant_id' => $variant->id,
            'supplier_sku' => 'PRIORITY-SKU',
            'stock_quantity' => 7,
            'match_status' => SupplierProduct::MATCH_STATUS_MATCHED,
            'match_method' => SupplierProduct::MATCH_METHOD_SKU,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        $resolved = app(ProductAvailabilityResolver::class)->resolve($variant->fresh());

        $this->assertSame('supplier', $resolved['source_type']);
        $this->assertSame(7, $resolved['quantity']);
        $this->assertSame('Helikon / Direct-Action', $resolved['supplier_name']);
    }

    public function test_barcode_match_takes_priority_over_sku(): void
    {
        $byBarcode = $this->createVariant('Prezioso', 'OTHER-SKU', '5901234123457');
        $this->createVariant('Prezioso', 'FEED-SKU', '9999999999999');

        $matcher = new VendorSkuMatcher(['Prezioso']);
        $variants = $matcher->loadShopifyVariants();

        $match = $matcher->match('FEED-SKU', $variants, collect(), '5901234123457');

        $this->assertSame($byBarcode->id, $match['variant']?->id);
        $this->assertSame(SupplierProduct::MATCH_METHOD_BARCODE, $match['match_method']);
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
            'title' => 'Product',
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
