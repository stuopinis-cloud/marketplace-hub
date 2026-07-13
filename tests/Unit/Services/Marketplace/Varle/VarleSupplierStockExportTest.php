<?php

namespace Tests\Unit\Services\Marketplace\Varle;

use App\Models\InventoryLevel;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Marketplace\Varle\VarleXmlExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\VarleCatalogFixtures;
use Tests\TestCase;

class VarleSupplierStockExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['marketplace.exports.varle.store_url' => 'https://ebunkeris.lt']);
    }

    public function test_local_stock_wins_over_supplier_stock_in_xml(): void
    {
        $variant = VarleCatalogFixtures::createSimpleDefaultTitleProduct();
        $variant->inventoryLevels()->update(['quantity' => 5]);
        $this->attachSupplierStock($variant, 20);

        $this->app->make(VarleXmlExporter::class)->export();
        $xml = Storage::disk('public')->get('feeds/varle.xml');

        $this->assertStringContainsString('<quantity>5</quantity>', $xml);
        $this->assertStringContainsString('<delivery_text><![CDATA[1-2 d.d.]]></delivery_text>', $xml);
    }

    public function test_supplier_stock_used_when_local_stock_is_zero(): void
    {
        $supplier = Supplier::query()->create([
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
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_variant_id' => $variant->id,
            'supplier_sku' => (string) $variant->sku,
            'stock_quantity' => 12,
            'match_status' => SupplierProduct::MATCH_STATUS_MATCHED,
            'match_method' => SupplierProduct::MATCH_METHOD_SKU,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        $this->app->make(VarleXmlExporter::class)->export();
        $xml = Storage::disk('public')->get('feeds/varle.xml');

        $this->assertStringContainsString('<quantity>12</quantity>', $xml);
        $this->assertStringContainsString('<delivery_text><![CDATA[5-10 d.d.]]></delivery_text>', $xml);
        $this->assertStringContainsString('<barcode>5901234567890</barcode>', $xml);
    }

    public function test_stale_supplier_stock_is_ignored(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'M-Tac',
            'code' => 'mtac',
            'enabled' => true,
            'in_stock_delivery_text' => '5-10 d.d.',
            'stale_after_minutes' => 60,
        ]);

        $variant = VarleCatalogFixtures::createSimpleDefaultTitleProduct(
            productOverrides: ['vendor' => 'M-Tac'],
            variantOverrides: ['backorder_allowed' => false],
        );
        $variant->inventoryLevels()->update(['quantity' => 0]);
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_variant_id' => $variant->id,
            'supplier_sku' => (string) $variant->sku,
            'stock_quantity' => 12,
            'match_status' => SupplierProduct::MATCH_STATUS_MATCHED,
            'match_method' => SupplierProduct::MATCH_METHOD_SKU,
            'enabled' => true,
            'last_synced_at' => now()->subHours(3),
        ]);

        $result = $this->app->make(VarleXmlExporter::class)->export();

        $this->assertSame(0, $result->exportedVariants);
        $this->assertStringNotContainsString('<product>', Storage::disk('public')->get('feeds/varle.xml'));
    }

    private function attachSupplierStock($variant, int $quantity): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'M-Tac',
            'code' => 'mtac',
            'enabled' => true,
            'in_stock_delivery_text' => '5-10 d.d.',
            'stale_after_minutes' => 1800,
        ]);

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_variant_id' => $variant->id,
            'supplier_sku' => (string) $variant->sku,
            'stock_quantity' => $quantity,
            'match_status' => SupplierProduct::MATCH_STATUS_MATCHED,
            'match_method' => SupplierProduct::MATCH_METHOD_SKU,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);
    }
}
