<?php

namespace Tests\Feature\Console;

use App\Enums\ProductStatus;
use App\Models\CategoryMapping;
use App\Models\InventoryLevel;
use App\Models\MarketplaceChannel;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Source;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Marketplace\Pigu\PiguExportOptions;
use App\Services\Marketplace\Pigu\PiguOfferFeedExporter;
use App\Services\Marketplace\Pigu\PiguSupplierOfferConfig;
use App\Services\Marketplace\Pigu\PiguSupplierPriceCalculator;
use App\Services\Marketplace\Pigu\PiguSupplierXmlFieldDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PiguSupplierOnlyOffersExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        config([
            'marketplace.exports.pigu.local_collection_hours' => 24,
            'marketplace.exports.pigu.supplier_collection_hours' => 168,
            'marketplace.exports.pigu.backorder_collection_hours' => 240,
        ]);

        $this->ensurePiguChannel();
    }

    public function test_supplier_only_offer_uses_supplier_price_with_vat_and_240_hours(): void
    {
        $supplier = $this->createPiguXmlSupplier();

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_variant_id' => null,
            'supplier_sku' => 'EXT-100',
            'barcode' => '5901234567890',
            'title' => 'External Product',
            'price' => 100.00,
            'stock_quantity' => 15,
            'match_status' => SupplierProduct::MATCH_STATUS_UNMATCHED,
            'availability_status' => SupplierProduct::AVAILABILITY_AVAILABLE,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        $result = app(PiguOfferFeedExporter::class)->export(new PiguExportOptions(allValid: true));
        $xml = Storage::disk('public')->get($result['feed_path']);

        $this->assertSame(1, $result['offers_exported']);
        $this->assertSame(1, $result['supplier_only_offers_count']);
        $this->assertSame(['240' => 1], $result['offers_by_collectionhours']);
        $this->assertStringContainsString('<sku><![CDATA[EXT-100]]></sku>', $xml);
        $this->assertStringContainsString('<ean><![CDATA[5901234567890]]></ean>', $xml);
        $this->assertStringContainsString('<price-before-discount>121.00</price-before-discount>', $xml);
        $this->assertStringContainsString('<price-after-discount>121.00</price-after-discount>', $xml);
        $this->assertStringContainsString('<stock>15</stock>', $xml);
        $this->assertStringContainsString('<collectionhours>240</collectionhours>', $xml);
    }

    public function test_vat_included_price_is_not_increased(): void
    {
        $supplier = $this->createPiguXmlSupplier([
            'price_includes_vat' => true,
            'vat_rate' => 21,
        ]);

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'EXT-VAT',
            'barcode' => '5901234567891',
            'price' => 49.99,
            'stock_quantity' => 3,
            'match_status' => SupplierProduct::MATCH_STATUS_UNMATCHED,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        $price = app(PiguSupplierPriceCalculator::class)->calculate(49.99, $supplier);
        $this->assertSame(49.99, $price);

        $result = app(PiguOfferFeedExporter::class)->export(new PiguExportOptions(allValid: true));
        $xml = Storage::disk('public')->get($result['feed_path']);
        $this->assertStringContainsString('<price-before-discount>49.99</price-before-discount>', $xml);
    }

    public function test_local_shopify_stock_overrides_supplier_and_keeps_shopify_price(): void
    {
        $variant = $this->seedShopifyVariant(
            sku: 'SHARED-1',
            barcode: '5901234567001',
            price: 29.50,
            localQty: 4,
        );

        $supplier = $this->createPiguXmlSupplier();
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_variant_id' => $variant->id,
            'supplier_sku' => 'SUP-SHARED-1',
            'barcode' => '5901234567001',
            'price' => 99.00,
            'stock_quantity' => 50,
            'match_status' => SupplierProduct::MATCH_STATUS_MATCHED,
            'match_method' => SupplierProduct::MATCH_METHOD_BARCODE,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        $result = app(PiguOfferFeedExporter::class)->export(new PiguExportOptions(allValid: true));
        $xml = Storage::disk('public')->get($result['feed_path']);

        $this->assertSame(1, $result['offers_exported']);
        $this->assertSame(1, $result['local_offers_count']);
        $this->assertSame(0, $result['supplier_only_offers_count']);
        $this->assertStringContainsString('<sku><![CDATA[SHARED-1]]></sku>', $xml);
        $this->assertStringContainsString('<price-before-discount>29.50</price-before-discount>', $xml);
        $this->assertStringContainsString('<stock>4</stock>', $xml);
        $this->assertStringContainsString('<collectionhours>24</collectionhours>', $xml);
        $this->assertStringNotContainsString('<stock>54</stock>', $xml);
    }

    public function test_shopify_zero_stock_with_supplier_stock_uses_pigu_supplier_hours(): void
    {
        $variant = $this->seedShopifyVariant(
            sku: 'SHARED-2',
            barcode: '5901234567002',
            price: 39.00,
            localQty: 0,
        );

        $supplier = $this->createPiguXmlSupplier(['collection_hours' => 240]);
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_variant_id' => $variant->id,
            'supplier_sku' => 'SUP-SHARED-2',
            'barcode' => '5901234567002',
            'price' => 80.00,
            'stock_quantity' => 12,
            'match_status' => SupplierProduct::MATCH_STATUS_MATCHED,
            'match_method' => SupplierProduct::MATCH_METHOD_BARCODE,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        $result = app(PiguOfferFeedExporter::class)->export(new PiguExportOptions(allValid: true));
        $xml = Storage::disk('public')->get($result['feed_path']);

        $this->assertSame(1, $result['supplier_offers_count']);
        $this->assertStringContainsString('<sku><![CDATA[SHARED-2]]></sku>', $xml);
        $this->assertStringContainsString('<price-before-discount>39.00</price-before-discount>', $xml);
        $this->assertStringContainsString('<stock>12</stock>', $xml);
        $this->assertStringContainsString('<collectionhours>240</collectionhours>', $xml);
    }

    public function test_same_ean_different_sku_does_not_duplicate_offers(): void
    {
        $this->seedShopifyVariant(
            sku: 'SHOP-EAN',
            barcode: '5901234567111',
            price: 10.00,
            localQty: 2,
        );

        $supplier = $this->createPiguXmlSupplier();
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'EXT-EAN',
            'barcode' => '5901234567111',
            'price' => 20.00,
            'stock_quantity' => 8,
            'match_status' => SupplierProduct::MATCH_STATUS_UNMATCHED,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        $result = app(PiguOfferFeedExporter::class)->export(new PiguExportOptions(allValid: true));

        $this->assertSame(1, $result['offers_exported']);
        $this->assertSame(1, $result['duplicate_ean_count']);
        $this->assertSame(1, $result['local_offers_count']);
        $this->assertSame(0, $result['supplier_only_offers_count']);
    }

    public function test_stale_supplier_only_stock_is_skipped(): void
    {
        $supplier = $this->createPiguXmlSupplier();
        $supplier->update(['stale_after_minutes' => 60]);

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'STALE-1',
            'barcode' => '5901234567222',
            'price' => 10.00,
            'stock_quantity' => 5,
            'match_status' => SupplierProduct::MATCH_STATUS_UNMATCHED,
            'enabled' => true,
            'last_synced_at' => now()->subHours(3),
        ]);

        $result = app(PiguOfferFeedExporter::class)->export(new PiguExportOptions(allValid: true));

        $this->assertSame(0, $result['offers_exported']);
        $this->assertSame(1, $result['skipped_by_code']['stale_supplier_stock'] ?? 0);
    }

    public function test_zero_supplier_stock_is_skipped(): void
    {
        $supplier = $this->createPiguXmlSupplier();
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'OOS-1',
            'barcode' => '5901234567333',
            'price' => 10.00,
            'stock_quantity' => 0,
            'match_status' => SupplierProduct::MATCH_STATUS_UNMATCHED,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        $result = app(PiguOfferFeedExporter::class)->export(new PiguExportOptions(allValid: true));
        $this->assertSame(0, $result['offers_exported']);
        $this->assertSame(1, $result['skipped_by_code']['no_exportable_stock'] ?? 0);
    }

    public function test_missing_ean_is_skipped(): void
    {
        $supplier = $this->createPiguXmlSupplier();
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'NO-EAN',
            'barcode' => null,
            'price' => 10.00,
            'stock_quantity' => 2,
            'match_status' => SupplierProduct::MATCH_STATUS_UNMATCHED,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        $result = app(PiguOfferFeedExporter::class)->export(new PiguExportOptions(allValid: true));
        $this->assertSame(1, $result['skipped_by_code']['missing_ean'] ?? 0);
    }

    public function test_field_detector_autodetects_common_paths(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<products>
  <product>
    <sku>A1</sku>
    <ean>5901234567444</ean>
    <stock>3</stock>
    <price>12.50</price>
    <title>Sample</title>
  </product>
  <product>
    <sku>A2</sku>
    <ean>5901234567555</ean>
    <stock>1</stock>
    <price>9.99</price>
    <title>Sample 2</title>
  </product>
</products>
XML;

        $detected = app(PiguSupplierXmlFieldDetector::class)->detect($xml);

        $this->assertSame('//product', $detected['item_path']);
        $this->assertSame('sku', $detected['sku_path']);
        $this->assertSame('ean', $detected['barcode_path']);
        $this->assertSame('stock', $detected['stock_path']);
        $this->assertSame('price', $detected['price_path']);
        $this->assertSame(2, $detected['item_count']);
        $this->assertCount(2, $detected['preview_rows']);
    }

    public function test_public_offers_route_serves_generated_feed(): void
    {
        $supplier = $this->createPiguXmlSupplier();
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'ROUTE-1',
            'barcode' => '5901234567666',
            'price' => 10.00,
            'stock_quantity' => 2,
            'match_status' => SupplierProduct::MATCH_STATUS_UNMATCHED,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        app(PiguOfferFeedExporter::class)->export(new PiguExportOptions(allValid: true));

        $response = $this->get('/feeds/pigu-offers.xml');
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $this->assertStringContainsString('<sku><![CDATA[ROUTE-1]]></sku>', $response->getContent());
    }

    public function test_export_is_idempotent_for_repeated_runs(): void
    {
        $supplier = $this->createPiguXmlSupplier();
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'IDEM-1',
            'barcode' => '5901234567777',
            'price' => 10.00,
            'stock_quantity' => 2,
            'match_status' => SupplierProduct::MATCH_STATUS_UNMATCHED,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        $first = app(PiguOfferFeedExporter::class)->export(new PiguExportOptions(allValid: true));
        $xml1 = Storage::disk('public')->get($first['feed_path']);
        $second = app(PiguOfferFeedExporter::class)->export(new PiguExportOptions(allValid: true));
        $xml2 = Storage::disk('public')->get($second['feed_path']);

        $this->assertSame($first['offers_exported'], $second['offers_exported']);
        $this->assertSame($xml1, $xml2);
    }

    private function ensurePiguChannel(): void
    {
        MarketplaceChannel::query()->firstOrCreate(
            ['type' => 'pigu'],
            ['name' => 'Pigu / PHH', 'enabled' => true, 'config' => []],
        );
    }

    /**
     * @param  array<string, mixed>  $piguConfig
     */
    private function createPiguXmlSupplier(array $piguConfig = []): Supplier
    {
        return Supplier::query()->create([
            'name' => 'Pigu XML Supplier',
            'code' => 'pigu-xml-'.uniqid(),
            'enabled' => true,
            'connector_type' => Supplier::CONNECTOR_XML_URL,
            'endpoint_url' => 'https://example.test/feed.xml',
            'stock_priority' => 10,
            'stale_after_minutes' => 2880,
            'force_daily_sync' => true,
            'sync_enabled' => true,
            'config' => [
                'xml_item_path' => '//product',
                'xml_sku_path' => 'sku',
                'xml_barcode_path' => 'ean',
                'xml_stock_path' => 'stock',
                'xml_price_path' => 'price',
                'match_by_barcode' => true,
                PiguSupplierOfferConfig::CONFIG_KEY => PiguSupplierOfferConfig::normalize(array_merge([
                    'enabled' => true,
                    'export_supplier_only' => true,
                    'collection_hours' => 240,
                    'price_includes_vat' => false,
                    'vat_rate' => 21,
                ], $piguConfig)),
            ],
        ]);
    }

    private function seedShopifyVariant(string $sku, string $barcode, float $price, int $localQty): ProductVariant
    {
        $source = Source::query()->firstOrCreate(
            ['type' => 'shopify'],
            ['name' => 'Shopify', 'enabled' => true, 'config' => []],
        );

        $channel = MarketplaceChannel::query()->where('type', 'pigu')->firstOrFail();

        CategoryMapping::query()->firstOrCreate(
            [
                'marketplace_channel_id' => $channel->id,
                'source_type' => 'product_type',
                'source_value' => 'OfferType',
            ],
            [
                'target_category_id' => '5001',
                'target_category_name' => 'Offer Cat',
                'target_category_path' => '5001|Offer Cat',
                'priority' => 10,
                'enabled' => true,
                'export_enabled' => true,
            ],
        );

        $product = Product::query()->create([
            'source_id' => $source->id,
            'external_id' => 'p-'.uniqid(),
            'title' => 'Shopify '.$sku,
            'description_html' => '<p>desc</p>',
            'vendor' => 'Brand',
            'product_type' => 'OfferType',
            'status' => ProductStatus::Active,
            'handle' => 'h-'.strtolower($sku).'-'.uniqid(),
            'imported_at' => now(),
        ]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'url' => 'https://cdn.example.com/x.jpg',
            'position' => 0,
        ]);

        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'external_id' => 'v-'.$sku.'-'.uniqid(),
            'sku' => $sku,
            'barcode' => $barcode,
            'title' => 'Default Title',
            'price' => $price,
            'image_url' => 'https://cdn.example.com/x.jpg',
            'option1_name' => 'Title',
            'option1_value' => 'Default Title',
            'backorder_allowed' => false,
            'inventory_policy' => 'DENY',
        ]);

        InventoryLevel::query()->create([
            'variant_id' => $variant->id,
            'warehouse_name' => 'Shopify',
            'quantity' => $localQty,
        ]);

        return $variant;
    }
}
