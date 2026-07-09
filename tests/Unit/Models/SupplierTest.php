<?php

namespace Tests\Unit\Models;

use App\Models\Supplier;
use App\Models\SupplierProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_has_many_supplier_products(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Main supplier',
            'code' => 'MAIN',
            'enabled' => true,
        ]);

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'SUP-001',
            'stock_quantity' => 7,
            'enabled' => true,
        ]);

        $this->assertCount(1, $supplier->supplierProducts);
        $this->assertSame('SUP-001', $supplier->supplierProducts->first()?->supplier_sku);
    }
}
