<?php

namespace Tests\Unit\Services\Marketplace;

use App\Models\InventoryLevel;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Source;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Marketplace\ProductAvailabilityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductAvailabilityResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_shopify_stock_has_priority_over_supplier_stock(): void
    {
        $variant = $this->makeVariant();

        InventoryLevel::query()->create([
            'variant_id' => $variant->id,
            'warehouse_name' => 'Shopify',
            'quantity' => 3,
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'Supplier A',
            'enabled' => true,
            'in_stock_delivery_text' => '5-10 d.d.',
            'stale_after_minutes' => 1800,
        ]);

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_variant_id' => $variant->id,
            'supplier_sku' => 'SKU-1',
            'stock_quantity' => 25,
            'enabled' => true,
        ]);

        $resolved = app(ProductAvailabilityResolver::class)->resolve($variant->fresh());

        $this->assertTrue($resolved['exportable']);
        $this->assertTrue($resolved['available']);
        $this->assertSame('shopify', $resolved['source_type']);
        $this->assertSame('shopify', $resolved['source']);
        $this->assertSame(3, $resolved['quantity']);
        $this->assertSame(3, $resolved['local_quantity']);
    }

    public function test_supplier_stock_used_when_shopify_stock_is_zero(): void
    {
        $variant = $this->makeVariant();

        InventoryLevel::query()->create([
            'variant_id' => $variant->id,
            'warehouse_name' => 'Shopify',
            'quantity' => 0,
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'Supplier A',
            'enabled' => true,
            'in_stock_delivery_text' => '5-10 d.d.',
            'stale_after_minutes' => 1800,
        ]);

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_variant_id' => $variant->id,
            'supplier_sku' => 'SKU-1',
            'stock_quantity' => 11,
            'match_status' => SupplierProduct::MATCH_STATUS_MATCHED,
            'match_method' => SupplierProduct::MATCH_METHOD_SKU,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        $resolved = app(ProductAvailabilityResolver::class)->resolve($variant->fresh());

        $this->assertTrue($resolved['exportable']);
        $this->assertSame('supplier', $resolved['source_type']);
        $this->assertSame(11, $resolved['quantity']);
        $this->assertSame('5-10 d.d.', $resolved['delivery_text']);
    }

    public function test_backorder_is_used_when_no_shopify_or_supplier_stock(): void
    {
        $variant = $this->makeVariant(['backorder_allowed' => true]);

        InventoryLevel::query()->create([
            'variant_id' => $variant->id,
            'warehouse_name' => 'Shopify',
            'quantity' => 0,
        ]);

        $resolved = app(ProductAvailabilityResolver::class)->resolve($variant->fresh());

        $this->assertTrue($resolved['available']);
        $this->assertSame('backorder', $resolved['source']);
        $this->assertSame(0, $resolved['quantity']);
        $this->assertNull($resolved['delivery_days_min']);
        $this->assertNull($resolved['delivery_days_max']);
    }

    public function test_unavailable_when_no_stock_and_backorder_not_allowed(): void
    {
        $variant = $this->makeVariant(['backorder_allowed' => false]);

        InventoryLevel::query()->create([
            'variant_id' => $variant->id,
            'warehouse_name' => 'Shopify',
            'quantity' => 0,
        ]);

        $resolved = app(ProductAvailabilityResolver::class)->resolve($variant->fresh());

        $this->assertFalse($resolved['exportable']);
        $this->assertFalse($resolved['available']);
        $this->assertNull($resolved['source_type']);
        $this->assertSame(0, $resolved['quantity']);
    }

    public function test_stale_supplier_stock_is_ignored(): void
    {
        $variant = $this->makeVariant(['backorder_allowed' => false]);
        $supplier = Supplier::query()->create([
            'name' => 'M-Tac',
            'enabled' => true,
            'in_stock_delivery_text' => '5-10 d.d.',
            'stale_after_minutes' => 30,
        ]);

        InventoryLevel::query()->create([
            'variant_id' => $variant->id,
            'warehouse_name' => 'Shopify',
            'quantity' => 0,
        ]);

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_variant_id' => $variant->id,
            'supplier_sku' => 'SKU-1',
            'stock_quantity' => 8,
            'match_status' => SupplierProduct::MATCH_STATUS_MATCHED,
            'match_method' => SupplierProduct::MATCH_METHOD_SKU,
            'enabled' => true,
            'last_synced_at' => now()->subHour(),
        ]);

        $resolved = app(ProductAvailabilityResolver::class)->resolve($variant->fresh());

        $this->assertFalse($resolved['exportable']);
        $this->assertTrue($resolved['is_stale']);
        $this->assertSame('supplier_stock_stale', $resolved['issue_code']);
    }

    private function makeVariant(array $overrides = []): ProductVariant
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
            'status' => 'active',
            'imported_at' => now(),
        ]);

        return ProductVariant::query()->create(array_merge([
            'product_id' => $product->id,
            'external_id' => 'variant-'.uniqid(),
            'sku' => 'SKU-1',
            'price' => 10,
            'backorder_allowed' => false,
        ], $overrides));
    }
}
