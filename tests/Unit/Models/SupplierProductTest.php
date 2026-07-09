<?php

namespace Tests\Unit\Models;

use App\Models\ProductVariant;
use App\Models\Source;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_product_belongs_to_supplier_and_variant(): void
    {
        $source = Source::query()->create([
            'type' => 'shopify',
            'name' => 'Shopify',
            'enabled' => true,
            'config' => [],
        ]);

        $product = Product::query()->create([
            'source_id' => $source->id,
            'external_id' => 'product-1',
            'title' => 'P1',
            'status' => 'active',
            'imported_at' => now(),
        ]);

        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'external_id' => 'variant-1',
            'sku' => 'SKU-1',
            'price' => 10,
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'Main supplier',
            'code' => 'MAIN',
            'enabled' => true,
        ]);

        $supplierProduct = SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_variant_id' => $variant->id,
            'supplier_sku' => 'SKU-1',
            'stock_quantity' => 12,
            'enabled' => true,
        ]);

        $this->assertTrue($supplierProduct->supplier->is($supplier));
        $this->assertTrue($supplierProduct->productVariant->is($variant));
    }

    public function test_unique_supplier_and_supplier_sku_constraint(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Main supplier',
            'code' => 'MAIN',
            'enabled' => true,
        ]);

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'DUP-1',
            'stock_quantity' => 1,
            'enabled' => true,
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'DUP-1',
            'stock_quantity' => 2,
            'enabled' => true,
        ]);
    }
}
