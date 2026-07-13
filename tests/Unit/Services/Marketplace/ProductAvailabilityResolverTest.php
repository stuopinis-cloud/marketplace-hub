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

        $supplier = $this->makeSupplier();

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_variant_id' => $variant->id,
            'supplier_sku' => 'SKU-1',
            'stock_quantity' => 25,
            'match_status' => SupplierProduct::MATCH_STATUS_MATCHED,
            'match_method' => SupplierProduct::MATCH_METHOD_SKU,
            'availability_status' => SupplierProduct::AVAILABILITY_AVAILABLE,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        $resolved = app(ProductAvailabilityResolver::class)->resolve($variant->fresh());

        $this->assertTrue($resolved['exportable']);
        $this->assertSame('shopify', $resolved['source_type']);
        $this->assertSame(3, $resolved['quantity']);
        $this->assertSame(3, $resolved['local_quantity']);
        $this->assertFalse($resolved['used_availability_fallback']);
    }

    public function test_supplier_positive_stock_used_when_local_zero(): void
    {
        $variant = $this->makeVariant();
        $supplier = $this->makeSupplier();

        InventoryLevel::query()->create([
            'variant_id' => $variant->id,
            'warehouse_name' => 'Shopify',
            'quantity' => 0,
        ]);

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_variant_id' => $variant->id,
            'supplier_sku' => 'SKU-1',
            'stock_quantity' => 20,
            'match_status' => SupplierProduct::MATCH_STATUS_MATCHED,
            'match_method' => SupplierProduct::MATCH_METHOD_SKU,
            'availability_status' => SupplierProduct::AVAILABILITY_AVAILABLE,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        $resolved = app(ProductAvailabilityResolver::class)->resolve($variant->fresh());

        $this->assertTrue($resolved['exportable']);
        $this->assertSame('supplier', $resolved['source_type']);
        $this->assertSame(20, $resolved['quantity']);
        $this->assertSame('5-10 d.d.', $resolved['delivery_text']);
    }

    public function test_truthy_supplier_availability_without_numeric_quantity_uses_fallback_five(): void
    {
        $variant = $this->makeVariant();
        $supplier = $this->makeSupplier();

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_variant_id' => $variant->id,
            'supplier_sku' => 'SKU-1',
            'stock_quantity' => null,
            'availability_status' => SupplierProduct::AVAILABILITY_AVAILABLE,
            'raw_payload' => ['availability' => 'in stock'],
            'match_status' => SupplierProduct::MATCH_STATUS_MATCHED,
            'match_method' => SupplierProduct::MATCH_METHOD_SKU,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        $resolved = app(ProductAvailabilityResolver::class)->resolve($variant->fresh());

        $this->assertTrue($resolved['exportable']);
        $this->assertSame('supplier_availability_fallback', $resolved['source_type']);
        $this->assertSame(5, $resolved['quantity']);
        $this->assertTrue($resolved['used_availability_fallback']);
        $this->assertSame(5, $resolved['availability_fallback_quantity']);
        $this->assertNull($resolved['supplier_quantity']);
    }

    public function test_custom_supplier_fallback_quantity_is_used(): void
    {
        $variant = $this->makeVariant();
        $supplier = $this->makeSupplier(['availability_fallback_quantity' => 8]);

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_variant_id' => $variant->id,
            'supplier_sku' => 'SKU-1',
            'stock_quantity' => null,
            'availability_status' => SupplierProduct::AVAILABILITY_AVAILABLE,
            'raw_payload' => ['availability' => 'true'],
            'match_status' => SupplierProduct::MATCH_STATUS_MATCHED,
            'match_method' => SupplierProduct::MATCH_METHOD_SKU,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        $resolved = app(ProductAvailabilityResolver::class)->resolve($variant->fresh());

        $this->assertTrue($resolved['exportable']);
        $this->assertSame(8, $resolved['quantity']);
    }

    public function test_explicit_supplier_zero_does_not_use_availability_fallback(): void
    {
        $variant = $this->makeVariant();
        $supplier = $this->makeSupplier();

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_variant_id' => $variant->id,
            'supplier_sku' => 'SKU-1',
            'stock_quantity' => 0,
            'availability_status' => SupplierProduct::AVAILABILITY_UNAVAILABLE,
            'raw_payload' => ['availability' => 'in stock', 'stock' => '0'],
            'match_status' => SupplierProduct::MATCH_STATUS_MATCHED,
            'match_method' => SupplierProduct::MATCH_METHOD_SKU,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        $resolved = app(ProductAvailabilityResolver::class)->resolve($variant->fresh());

        $this->assertFalse($resolved['exportable']);
        $this->assertSame(0, $resolved['quantity']);
        $this->assertFalse($resolved['used_availability_fallback']);
    }

    public function test_falsy_supplier_availability_is_not_exportable(): void
    {
        $variant = $this->makeVariant();
        $supplier = $this->makeSupplier();

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_variant_id' => $variant->id,
            'supplier_sku' => 'SKU-1',
            'stock_quantity' => null,
            'availability_status' => SupplierProduct::AVAILABILITY_UNAVAILABLE,
            'raw_payload' => ['availability' => 'out of stock'],
            'match_status' => SupplierProduct::MATCH_STATUS_MATCHED,
            'match_method' => SupplierProduct::MATCH_METHOD_SKU,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        $resolved = app(ProductAvailabilityResolver::class)->resolve($variant->fresh());

        $this->assertFalse($resolved['exportable']);
        $this->assertSame(0, $resolved['quantity']);
    }

    public function test_unknown_supplier_availability_is_not_exportable(): void
    {
        $variant = $this->makeVariant();
        $supplier = $this->makeSupplier();

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_variant_id' => $variant->id,
            'supplier_sku' => 'SKU-1',
            'stock_quantity' => null,
            'availability_status' => SupplierProduct::AVAILABILITY_UNAVAILABLE,
            'raw_payload' => ['availability' => 'maybe'],
            'match_status' => SupplierProduct::MATCH_STATUS_MATCHED,
            'match_method' => SupplierProduct::MATCH_METHOD_SKU,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        $resolved = app(ProductAvailabilityResolver::class)->resolve($variant->fresh());

        $this->assertFalse($resolved['exportable']);
        $this->assertSame('supplier_availability_unknown', $resolved['issue_code']);
    }

    public function test_backorder_is_not_exportable_for_varle(): void
    {
        $variant = $this->makeVariant(['backorder_allowed' => true]);

        InventoryLevel::query()->create([
            'variant_id' => $variant->id,
            'warehouse_name' => 'Shopify',
            'quantity' => 0,
        ]);

        $resolved = app(ProductAvailabilityResolver::class)->resolve($variant->fresh());

        $this->assertFalse($resolved['exportable']);
        $this->assertSame(0, $resolved['quantity']);
        $this->assertSame('no_stock_anywhere', $resolved['issue_code']);
    }

    public function test_stale_supplier_stock_is_ignored(): void
    {
        $variant = $this->makeVariant(['backorder_allowed' => false]);
        $supplier = $this->makeSupplier(['stale_after_minutes' => 30]);

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
            'availability_status' => SupplierProduct::AVAILABILITY_AVAILABLE,
            'enabled' => true,
            'last_synced_at' => now()->subHour(),
        ]);

        $resolved = app(ProductAvailabilityResolver::class)->resolve($variant->fresh());

        $this->assertFalse($resolved['exportable']);
        $this->assertTrue($resolved['is_stale']);
        $this->assertSame('supplier_stock_stale', $resolved['issue_code']);
    }

    private function makeSupplier(array $overrides = []): Supplier
    {
        return Supplier::query()->create(array_merge([
            'name' => 'Supplier A',
            'code' => 'supplier-a',
            'enabled' => true,
            'in_stock_delivery_text' => '5-10 d.d.',
            'availability_fallback_quantity' => 5,
            'stale_after_minutes' => 1800,
        ], $overrides));
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
