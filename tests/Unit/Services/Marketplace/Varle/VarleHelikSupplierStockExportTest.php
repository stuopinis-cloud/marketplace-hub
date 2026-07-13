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

class VarleHelikSupplierStockExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['marketplace.exports.varle.store_url' => 'https://ebunkeris.lt']);
    }

    public function test_helikon_supplier_stock_exports_when_local_stock_is_zero(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Helikon / Direct-Action',
            'code' => 'helik',
            'enabled' => true,
            'in_stock_delivery_text' => '5-10 d.d.',
            'stale_after_minutes' => 1800,
        ]);

        $variant = VarleCatalogFixtures::createSimpleDefaultTitleProduct(
            productOverrides: ['vendor' => 'Helikon-Tex', 'handle' => 'helikon-jacket'],
        );
        $variant->inventoryLevels()->update(['quantity' => 0]);

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_variant_id' => $variant->id,
            'supplier_sku' => (string) $variant->sku,
            'stock_quantity' => 9,
            'match_status' => SupplierProduct::MATCH_STATUS_MATCHED,
            'match_method' => SupplierProduct::MATCH_METHOD_SKU,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        $this->app->make(VarleXmlExporter::class)->export();
        $xml = Storage::disk('public')->get('feeds/varle.xml');

        $this->assertStringContainsString('<quantity>9</quantity>', $xml);
        $this->assertStringContainsString('<delivery_text><![CDATA[5-10 d.d.]]></delivery_text>', $xml);
        $this->assertStringContainsString('<barcode>5901234567890</barcode>', $xml);
    }

    public function test_local_stock_still_wins_over_helikon_supplier_stock(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Helikon / Direct-Action',
            'code' => 'helik',
            'enabled' => true,
            'in_stock_delivery_text' => '5-10 d.d.',
            'stale_after_minutes' => 1800,
        ]);

        $variant = VarleCatalogFixtures::createSimpleDefaultTitleProduct(
            productOverrides: ['vendor' => 'Helikon-Tex'],
        );
        $variant->inventoryLevels()->update(['quantity' => 4]);

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_variant_id' => $variant->id,
            'supplier_sku' => (string) $variant->sku,
            'stock_quantity' => 15,
            'match_status' => SupplierProduct::MATCH_STATUS_MATCHED,
            'match_method' => SupplierProduct::MATCH_METHOD_SKU,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        $this->app->make(VarleXmlExporter::class)->export();
        $xml = Storage::disk('public')->get('feeds/varle.xml');

        $this->assertStringContainsString('<quantity>4</quantity>', $xml);
        $this->assertStringContainsString('<delivery_text><![CDATA[1-2 d.d.]]></delivery_text>', $xml);
    }
}
