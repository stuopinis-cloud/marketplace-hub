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
use App\Services\Marketplace\Pigu\PiguOfferFeedExporter;
use App\Services\Marketplace\Pigu\PiguProductXmlValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PiguOfferCollectionHoursTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_shopify_stock_uses_local_collection_hours(): void
    {
        config([
            'marketplace.exports.pigu.local_collection_hours' => 24,
            'marketplace.exports.pigu.supplier_collection_hours' => 168,
            'marketplace.exports.pigu.backorder_collection_hours' => 240,
        ]);

        $this->seedOfferProduct(localQty: 5, supplierQty: null, backorder: false, sku: 'SKU-LOCAL');

        $result = app(PiguOfferFeedExporter::class)->export();
        $xml = Storage::disk('public')->get($result['feed_path']);

        $this->assertSame(1, $result['offers_exported']);
        $this->assertSame(1, $result['local_offers_count']);
        $this->assertSame(0, $result['supplier_offers_count']);
        $this->assertSame(0, $result['backorder_offers_count']);
        $this->assertSame(['24' => 1], $result['offers_by_collectionhours']);
        $this->assertStringContainsString('<sku><![CDATA[SKU-LOCAL]]></sku>', $xml);
        $this->assertStringContainsString('<collectionhours>24</collectionhours>', $xml);
        $this->assertStringContainsString('<stock>5</stock>', $xml);
    }

    public function test_supplier_stock_uses_supplier_collection_hours(): void
    {
        config([
            'marketplace.exports.pigu.local_collection_hours' => 24,
            'marketplace.exports.pigu.supplier_collection_hours' => 168,
            'marketplace.exports.pigu.backorder_collection_hours' => 240,
        ]);

        $this->seedOfferProduct(localQty: 0, supplierQty: 7, backorder: false, sku: 'SKU-SUP');

        $result = app(PiguOfferFeedExporter::class)->export();
        $xml = Storage::disk('public')->get($result['feed_path']);

        $this->assertSame(1, $result['offers_exported']);
        $this->assertSame(0, $result['local_offers_count']);
        $this->assertSame(1, $result['supplier_offers_count']);
        $this->assertSame(0, $result['backorder_offers_count']);
        $this->assertSame(['168' => 1], $result['offers_by_collectionhours']);
        $this->assertStringContainsString('<sku><![CDATA[SKU-SUP]]></sku>', $xml);
        $this->assertStringContainsString('<collectionhours>168</collectionhours>', $xml);
        $this->assertStringContainsString('<stock>7</stock>', $xml);
    }

    public function test_backorder_uses_backorder_collection_hours(): void
    {
        config([
            'marketplace.exports.pigu.local_collection_hours' => 24,
            'marketplace.exports.pigu.supplier_collection_hours' => 168,
            'marketplace.exports.pigu.backorder_collection_hours' => 240,
        ]);

        $this->seedOfferProduct(localQty: 0, supplierQty: null, backorder: true, sku: 'SKU-BO');

        $result = app(PiguOfferFeedExporter::class)->export();
        $xml = Storage::disk('public')->get($result['feed_path']);

        $this->assertSame(1, $result['offers_exported']);
        $this->assertSame(0, $result['local_offers_count']);
        $this->assertSame(0, $result['supplier_offers_count']);
        $this->assertSame(1, $result['backorder_offers_count']);
        $this->assertSame(['240' => 1], $result['offers_by_collectionhours']);
        $this->assertStringContainsString('<sku><![CDATA[SKU-BO]]></sku>', $xml);
        $this->assertStringContainsString('<collectionhours>240</collectionhours>', $xml);
        $this->assertStringContainsString('<stock>1</stock>', $xml);
    }

    public function test_local_stock_wins_over_supplier_for_collection_hours(): void
    {
        config([
            'marketplace.exports.pigu.local_collection_hours' => 24,
            'marketplace.exports.pigu.supplier_collection_hours' => 168,
        ]);

        $this->seedOfferProduct(localQty: 2, supplierQty: 9, backorder: false, sku: 'SKU-BOTH');

        $result = app(PiguOfferFeedExporter::class)->export();

        $this->assertSame(1, $result['local_offers_count']);
        $this->assertSame(0, $result['supplier_offers_count']);
        $this->assertSame(['24' => 1], $result['offers_by_collectionhours']);
    }

    public function test_no_stock_without_backorder_is_skipped(): void
    {
        $this->seedOfferProduct(localQty: 0, supplierQty: null, backorder: false, sku: 'SKU-OOS');

        $result = app(PiguOfferFeedExporter::class)->export();

        $this->assertSame(0, $result['offers_exported']);
        $this->assertSame(0, $result['local_offers_count']);
    }

    public function test_offers_validation_requires_positive_collectionhours(): void
    {
        $this->seedOfferProduct(localQty: 3, supplierQty: null, backorder: false, sku: 'SKU-VALID');
        app(PiguOfferFeedExporter::class)->export();

        $validation = app(PiguProductXmlValidator::class)->validateOffersFeed();

        $this->assertTrue($validation['valid']);
        $this->assertSame(1, $validation['offers_checked']);
        $this->assertSame([], $validation['errors_by_type']);
    }

    private function seedOfferProduct(
        int $localQty,
        ?int $supplierQty,
        bool $backorder,
        string $sku,
    ): Product {
        $source = Source::query()->firstOrCreate(
            ['type' => 'shopify'],
            ['name' => 'Shopify', 'enabled' => true, 'config' => []],
        );

        $channel = MarketplaceChannel::query()->firstOrCreate(
            ['type' => 'pigu'],
            ['name' => 'Pigu / PHH', 'enabled' => true, 'config' => ['require_category_mapping' => true]],
        );

        CategoryMapping::query()->create([
            'marketplace_channel_id' => $channel->id,
            'source_type' => 'product_type',
            'source_value' => 'OfferType',
            'target_category_id' => '5001',
            'target_category_name' => 'Offer Cat',
            'target_category_path' => '5001|Offer Cat',
            'priority' => 10,
            'enabled' => true,
            'export_enabled' => true,
        ]);

        $product = Product::query()->create([
            'source_id' => $source->id,
            'external_id' => 'p-offer-'.uniqid(),
            'title' => 'Offer Product '.$sku,
            'description_html' => '<p>Offer description</p>',
            'vendor' => 'OfferBrand',
            'product_type' => 'OfferType',
            'status' => ProductStatus::Active,
            'handle' => 'offer-'.strtolower($sku),
            'imported_at' => now(),
        ]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'url' => 'https://cdn.example.com/offer.jpg',
            'position' => 0,
        ]);

        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'external_id' => 'v-'.$sku,
            'sku' => $sku,
            'barcode' => '590'.substr(str_pad((string) abs(crc32($sku)), 10, '0'), 0, 10),
            'title' => 'Default Title',
            'price' => 19.99,
            'image_url' => 'https://cdn.example.com/offer.jpg',
            'option1_name' => 'Title',
            'option1_value' => 'Default Title',
            'backorder_allowed' => $backorder,
            'inventory_policy' => $backorder ? 'CONTINUE' : 'DENY',
        ]);

        InventoryLevel::query()->create([
            'variant_id' => $variant->id,
            'warehouse_name' => 'Shopify',
            'quantity' => $localQty,
        ]);

        if ($supplierQty !== null) {
            $supplier = Supplier::query()->create([
                'name' => 'Offer Supplier',
                'code' => 'offer-supplier-'.uniqid(),
                'enabled' => true,
                'in_stock_delivery_text' => '5-10 d.d.',
                'stale_after_minutes' => 1800,
            ]);

            SupplierProduct::query()->create([
                'supplier_id' => $supplier->id,
                'product_variant_id' => $variant->id,
                'supplier_sku' => $sku,
                'stock_quantity' => $supplierQty,
                'match_status' => SupplierProduct::MATCH_STATUS_MATCHED,
                'match_method' => SupplierProduct::MATCH_METHOD_SKU,
                'availability_status' => SupplierProduct::AVAILABILITY_AVAILABLE,
                'enabled' => true,
                'last_synced_at' => now(),
            ]);
        }

        return $product->fresh(['variants.inventoryLevels', 'variants.supplierProducts.supplier']);
    }
}
