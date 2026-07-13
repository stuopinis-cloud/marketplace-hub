<?php

namespace Tests\Unit\Services\Marketplace\Varle;

use App\Models\InventoryLevel;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Source;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Marketplace\Varle\VarleXmlExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\VarleCatalogFixtures;
use Tests\TestCase;

class VarleXmlQuantityInvariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_generated_xml_never_contains_zero_or_negative_quantity(): void
    {
        VarleCatalogFixtures::createExportableVariant(productOverrides: ['handle' => 'positive-local']);
        VarleCatalogFixtures::createSimpleDefaultTitleProduct();

        $this->makeExporter()->export();
        $xml = Storage::disk('public')->get('feeds/varle.xml');

        $this->assertNoNonPositiveQuantities($xml);
    }

    public function test_simple_product_with_supplier_availability_fallback_exports_quantity_five(): void
    {
        $variant = VarleCatalogFixtures::createSimpleDefaultTitleProduct();
        $variant->inventoryLevels()->update(['quantity' => 0]);

        $supplier = Supplier::query()->create([
            'name' => 'M-Tac',
            'code' => 'mtac',
            'enabled' => true,
            'in_stock_delivery_text' => '5-10 d.d.',
            'availability_fallback_quantity' => 5,
            'stale_after_minutes' => 1800,
        ]);

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_variant_id' => $variant->id,
            'supplier_sku' => $variant->sku,
            'stock_quantity' => null,
            'availability_status' => 'available',
            'raw_payload' => ['availability' => 'in stock'],
            'match_status' => SupplierProduct::MATCH_STATUS_MATCHED,
            'match_method' => SupplierProduct::MATCH_METHOD_SKU,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        $this->makeExporter()->export();
        $section = $this->extractProductXmlSection(
            Storage::disk('public')->get('feeds/varle.xml'),
            'simple-default-title-product',
        );

        $this->assertStringContainsString('<quantity>5</quantity>', $section);
        $this->assertNoNonPositiveQuantities($section);
    }

    public function test_variant_product_omits_zero_quantity_variants_and_skips_product_when_all_zero(): void
    {
        $product = VarleCatalogFixtures::createSizeOnlyProduct();
        $variants = ProductVariant::query()->where('product_id', $product->id)->orderBy('id')->get();

        foreach ($variants as $variant) {
            $variant->inventoryLevels()->update(['quantity' => 0]);
        }

        $result = $this->makeExporter()->export();

        $this->assertSame(0, $result->exportedVariants);
        $this->assertStringNotContainsString('<quantity>0</quantity>', Storage::disk('public')->get('feeds/varle.xml'));
    }

    public function test_helikon_supplier_quantity_zero_is_not_exported(): void
    {
        $variant = VarleCatalogFixtures::createExportableVariant(productOverrides: [
            'handle' => 'helikon-zero',
            'vendor' => 'Helikon-Tex',
        ]);
        $variant->inventoryLevels()->update(['quantity' => 0]);

        $supplier = Supplier::query()->create([
            'name' => 'Helikon / Direct-Action',
            'code' => 'helik',
            'enabled' => true,
            'in_stock_delivery_text' => '5-10 d.d.',
            'availability_fallback_quantity' => 5,
            'stale_after_minutes' => 1800,
        ]);

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_variant_id' => $variant->id,
            'supplier_sku' => $variant->sku,
            'stock_quantity' => 0,
            'availability_status' => 'unavailable',
            'match_status' => SupplierProduct::MATCH_STATUS_MATCHED,
            'match_method' => SupplierProduct::MATCH_METHOD_SKU,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        $result = $this->makeExporter()->export();

        $this->assertSame(0, $result->exportedVariants);
        $this->assertStringNotContainsString('helikon-zero', Storage::disk('public')->get('feeds/varle.xml'));
    }

    private function makeExporter(): VarleXmlExporter
    {
        return app(VarleXmlExporter::class);
    }

    private function extractProductXmlSection(string $xml, string $handle): string
    {
        if (! preg_match('/<product>[\s\S]*?<id>'.preg_quote($handle, '/').'<\/id>[\s\S]*?<\/product>/', $xml, $matches)) {
            $this->fail('Product XML section not found for handle: '.$handle);
        }

        return $matches[0];
    }

    private function assertNoNonPositiveQuantities(string $xml): void
    {
        preg_match_all('/<quantity>(-?\d+)<\/quantity>/', $xml, $matches);

        foreach ($matches[1] ?? [] as $quantity) {
            $this->assertGreaterThan(0, (int) $quantity, 'Found non-positive quantity in Varle XML: '.$quantity);
        }

        $this->assertStringNotContainsString('<quantity>0</quantity>', $xml);
    }
}
