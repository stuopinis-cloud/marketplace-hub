<?php

namespace Tests\Unit\Services\Marketplace\Varle;

use App\Models\ProductVariant;
use App\Models\VendorDeliveryRule;
use App\Services\Marketplace\Varle\VarleDeliveryResolver;
use App\Services\Marketplace\Varle\VarleStockEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\VarleCatalogFixtures;
use Tests\TestCase;

class VarleDeliveryAndStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_delivery_rule_is_used_for_exact_vendor_match(): void
    {
        VendorDeliveryRule::query()->create([
            'vendor' => 'Helikon-Tex',
            'enabled' => true,
            'in_stock_delivery_text' => '2-4 d.d.',
            'backorder_delivery_text' => '10-20 d.d.',
            'allow_backorder_export' => true,
        ]);

        $variant = VarleCatalogFixtures::createExportableVariant(productOverrides: ['vendor' => 'Helikon-Tex']);
        $rule = app(VarleDeliveryResolver::class)->resolveForProduct($variant->product, []);

        $this->assertSame('vendor_rule_found', $rule['status']);
        $this->assertSame('2-4 d.d.', $rule['in_stock_delivery_text']);
        $this->assertSame('10-20 d.d.', $rule['backorder_delivery_text']);
    }

    public function test_in_stock_variant_exports_with_vendor_in_stock_delivery_text(): void
    {
        VendorDeliveryRule::query()->create([
            'vendor' => 'Vendor Name',
            'enabled' => true,
            'in_stock_delivery_text' => '2-4 d.d.',
            'backorder_delivery_text' => '10-20 d.d.',
            'allow_backorder_export' => true,
        ]);

        VarleCatalogFixtures::createExportableVariant(productOverrides: ['handle' => 'delivery-stock']);

        $this->makeExporter()->export();
        $xml = \Illuminate\Support\Facades\Storage::disk('public')->get('feeds/varle.xml');

        $this->assertStringContainsString('<delivery_text><![CDATA[2-4 d.d.]]></delivery_text>', $xml);
    }

    public function test_backorder_variant_exports_with_quantity_one(): void
    {
        VendorDeliveryRule::query()->create([
            'vendor' => 'Vendor Name',
            'enabled' => true,
            'in_stock_delivery_text' => '1-2 d.d.',
            'backorder_delivery_text' => '5-10 d.d.',
            'allow_backorder_export' => true,
        ]);

        $variant = VarleCatalogFixtures::createExportableVariant(productOverrides: ['handle' => 'delivery-backorder']);
        ProductVariant::query()->whereKey($variant->id)->update([
            'inventory_policy' => 'CONTINUE',
            'backorder_allowed' => true,
        ]);
        $variant->inventoryLevels()->update(['quantity' => 0]);

        $result = $this->makeExporter()->export();
        $xml = \Illuminate\Support\Facades\Storage::disk('public')->get('feeds/varle.xml');

        $this->assertSame(1, $result->exportedVariants);
        $this->assertStringContainsString('<quantity>1</quantity>', $xml);
        $this->assertStringContainsString('<delivery_text><![CDATA[5-10 d.d.]]></delivery_text>', $xml);
        $this->assertStringNotContainsString('<quantity>0</quantity>', $xml);
    }

    public function test_out_of_stock_deny_variant_is_skipped(): void
    {
        $variant = VarleCatalogFixtures::createExportableVariant(productOverrides: ['handle' => 'delivery-deny']);
        ProductVariant::query()->whereKey($variant->id)->update([
            'inventory_policy' => 'DENY',
            'backorder_allowed' => false,
        ]);
        $variant->inventoryLevels()->update(['quantity' => 0]);

        $result = $this->makeExporter()->export();

        $this->assertSame(0, $result->exportedVariants);
        $this->assertGreaterThan(0, $result->skippedVariants);
    }

    public function test_vendor_disabling_backorders_skips_backorder_variant(): void
    {
        VendorDeliveryRule::query()->create([
            'vendor' => 'Vendor Name',
            'enabled' => true,
            'allow_backorder_export' => false,
        ]);

        $variant = VarleCatalogFixtures::createExportableVariant(productOverrides: ['handle' => 'delivery-vendor-block']);
        ProductVariant::query()->whereKey($variant->id)->update([
            'inventory_policy' => 'CONTINUE',
            'backorder_allowed' => true,
        ]);
        $variant->inventoryLevels()->update(['quantity' => 0]);

        $result = $this->makeExporter()->export();

        $this->assertSame(0, $result->exportedVariants);
    }

    public function test_stock_evaluator_marks_continue_policy_as_backorder_when_qty_zero(): void
    {
        $variant = new ProductVariant([
            'inventory_policy' => 'CONTINUE',
            'backorder_allowed' => true,
        ]);

        $result = app(VarleStockEvaluator::class)->assessVariant($variant, 0, [
            'allow_backorder_export' => true,
        ]);

        $this->assertTrue($result['exportable']);
        $this->assertSame(VarleStockEvaluator::CLASS_BACKORDER, $result['delivery_class']);
    }

    private function makeExporter(): \App\Services\Marketplace\Varle\VarleXmlExporter
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        return $this->app->make(\App\Services\Marketplace\Varle\VarleXmlExporter::class);
    }
}
