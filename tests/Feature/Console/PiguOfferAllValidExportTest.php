<?php

namespace Tests\Feature\Console;

use App\Enums\FeedFileStatus;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PiguOfferAllValidExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_valid_exports_variant_without_pigu_category_mapping(): void
    {
        $this->seedMinimalOfferVariant(
            sku: 'SKU-NOMAP',
            barcode: '5901234123457',
            price: 12.50,
            localQty: 3,
            withMapping: false,
            withImage: false,
            withDescription: false,
        );

        $result = app(PiguOfferFeedExporter::class)->export(new PiguExportOptions(allValid: true));
        $xml = Storage::disk('public')->get($result['feed_path']);

        $this->assertSame(1, $result['offers_exported']);
        $this->assertTrue($result['filter']['all_valid']);
        $this->assertStringContainsString('<sku><![CDATA[SKU-NOMAP]]></sku>', $xml);
        $this->assertStringContainsString('<ean><![CDATA[5901234123457]]></ean>', $xml);
        $this->assertStringContainsString('<stock>3</stock>', $xml);
    }

    public function test_all_valid_exports_without_dimensions_properties_images_description(): void
    {
        $this->seedMinimalOfferVariant(
            sku: 'SKU-BARE',
            barcode: '5901234123458',
            price: 9.99,
            localQty: 2,
            withMapping: false,
            withImage: false,
            withDescription: false,
            title: 'Bare',
        );

        $result = app(PiguOfferFeedExporter::class)->export(new PiguExportOptions(allValid: true));

        $this->assertSame(1, $result['offers_exported']);
        $this->assertSame(1, $result['local_offers_count']);
    }

    public function test_all_valid_skips_missing_sku(): void
    {
        $this->seedMinimalOfferVariant(sku: '', barcode: '5901234123457', price: 10, localQty: 1);

        $result = app(PiguOfferFeedExporter::class)->export(new PiguExportOptions(allValid: true));

        $this->assertSame(0, $result['offers_exported']);
        $this->assertSame(1, $result['skipped_by_code']['missing_sku'] ?? 0);
        $this->assertSkippedCsvContains('missing_sku');
    }

    public function test_all_valid_skips_missing_ean(): void
    {
        $this->seedMinimalOfferVariant(sku: 'SKU-NOEAN', barcode: '', price: 10, localQty: 1);

        $result = app(PiguOfferFeedExporter::class)->export(new PiguExportOptions(allValid: true));

        $this->assertSame(0, $result['offers_exported']);
        $this->assertSame(1, $result['skipped_by_code']['missing_ean'] ?? 0);
    }

    public function test_all_valid_skips_invalid_ean(): void
    {
        $this->seedMinimalOfferVariant(sku: 'SKU-BADEAN', barcode: '12345', price: 10, localQty: 1);

        $result = app(PiguOfferFeedExporter::class)->export(new PiguExportOptions(allValid: true));

        $this->assertSame(0, $result['offers_exported']);
        $this->assertSame(1, $result['skipped_by_code']['invalid_ean'] ?? 0);
    }

    public function test_all_valid_skips_duplicate_ean(): void
    {
        $this->seedMinimalOfferVariant(sku: 'SKU-A', barcode: '5901234123457', price: 10, localQty: 1, externalSuffix: 'a');
        $this->seedMinimalOfferVariant(sku: 'SKU-B', barcode: '5901234123457', price: 11, localQty: 2, externalSuffix: 'b');

        $result = app(PiguOfferFeedExporter::class)->export(new PiguExportOptions(allValid: true));
        $xml = Storage::disk('public')->get($result['feed_path']);

        $this->assertSame(1, $result['offers_exported']);
        $this->assertSame(1, $result['duplicate_ean_count']);
        $this->assertSame(1, $result['skipped_by_code']['duplicate_ean'] ?? 0);
        $this->assertSame(1, $result['unique_ean_count']);
        $this->assertStringContainsString('<sku><![CDATA[SKU-A]]></sku>', $xml);
        $this->assertStringNotContainsString('<sku><![CDATA[SKU-B]]></sku>', $xml);
    }

    public function test_all_valid_skips_zero_or_negative_price(): void
    {
        $this->seedMinimalOfferVariant(sku: 'SKU-ZEROPRICE', barcode: '5901234123402', price: 0, localQty: 1, externalSuffix: 'zp');
        $this->seedMinimalOfferVariant(sku: 'SKU-NEGPRICE', barcode: '5901234123404', price: -1.5, localQty: 1, externalSuffix: 'neg');

        $result = app(PiguOfferFeedExporter::class)->export(new PiguExportOptions(allValid: true));

        $this->assertSame(0, $result['offers_exported']);
        $this->assertSame(2, $result['skipped_by_code']['invalid_price'] ?? 0);
    }

    public function test_all_valid_skips_no_exportable_stock(): void
    {
        $this->seedMinimalOfferVariant(
            sku: 'SKU-OOS',
            barcode: '5901234123403',
            price: 10,
            localQty: 0,
            supplierQty: null,
            backorder: false,
        );

        $result = app(PiguOfferFeedExporter::class)->export(new PiguExportOptions(allValid: true));

        $this->assertSame(0, $result['offers_exported']);
        $this->assertSame(1, $result['skipped_by_code']['no_exportable_stock'] ?? 0);
    }

    public function test_all_valid_exports_local_supplier_and_backorder_collectionhours(): void
    {
        config([
            'marketplace.exports.pigu.local_collection_hours' => 24,
            'marketplace.exports.pigu.supplier_collection_hours' => 168,
            'marketplace.exports.pigu.backorder_collection_hours' => 240,
        ]);

        $this->seedMinimalOfferVariant(sku: 'SKU-L', barcode: '5901234123411', price: 10, localQty: 4, externalSuffix: 'l');
        $this->seedMinimalOfferVariant(sku: 'SKU-S', barcode: '5901234123412', price: 10, localQty: 0, supplierQty: 6, externalSuffix: 's');
        $this->seedMinimalOfferVariant(sku: 'SKU-B', barcode: '5901234123413', price: 10, localQty: 0, backorder: true, externalSuffix: 'b');

        $result = app(PiguOfferFeedExporter::class)->export(new PiguExportOptions(allValid: true));
        $xml = Storage::disk('public')->get($result['feed_path']);

        $this->assertSame(3, $result['offers_exported']);
        $this->assertSame(1, $result['local_offers_count']);
        $this->assertSame(1, $result['supplier_offers_count']);
        $this->assertSame(1, $result['backorder_offers_count']);
        $this->assertSame(['24' => 1, '168' => 1, '240' => 1], $result['offers_by_collectionhours']);
        $this->assertStringContainsString('<sku><![CDATA[SKU-L]]></sku>', $xml);
        $this->assertStringContainsString('<collectionhours>24</collectionhours>', $xml);
        $this->assertStringContainsString('<collectionhours>168</collectionhours>', $xml);
        $this->assertStringContainsString('<collectionhours>240</collectionhours>', $xml);
        $this->assertStringContainsString('<stock>4</stock>', $xml);
        $this->assertStringContainsString('<stock>6</stock>', $xml);
        $this->assertStringContainsString('<stock>1</stock>', $xml);
    }

    public function test_default_offers_export_still_requires_category_mapping(): void
    {
        $this->seedMinimalOfferVariant(
            sku: 'SKU-DEFAULT',
            barcode: '5901234123420',
            price: 15,
            localQty: 5,
            withMapping: false,
            withImage: true,
            withDescription: true,
        );

        $default = app(PiguOfferFeedExporter::class)->export();
        $allValid = app(PiguOfferFeedExporter::class)->export(new PiguExportOptions(allValid: true));

        $this->assertSame(0, $default['offers_exported']);
        $this->assertSame(1, $default['skipped_by_code']['not_exportable'] ?? 0);
        $this->assertSame(1, $allValid['offers_exported']);
    }

    public function test_all_valid_updates_feed_files_with_valid_status(): void
    {
        $this->seedMinimalOfferVariant(sku: 'SKU-FEED', barcode: '5901234123421', price: 8, localQty: 1);

        $result = app(PiguOfferFeedExporter::class)->export(new PiguExportOptions(allValid: true));

        $this->assertDatabaseHas('feed_files', [
            'filename' => 'pigu-offers.xml',
            'path' => 'feeds/pigu-offers.xml',
            'public_url' => 'https://hub.gudle.lt/feeds/pigu-offers.xml',
            'status' => FeedFileStatus::Generated->value,
        ]);
        $this->assertNotSame('pending', FeedFileStatus::Generated->value);
        $this->assertContains(
            FeedFileStatus::Generated->value,
            array_map(fn (FeedFileStatus $status) => $status->value, FeedFileStatus::cases()),
        );
        $this->assertSame(1, $result['offers_exported']);
    }

    public function test_all_valid_command_option_and_verbose_output(): void
    {
        $this->seedMinimalOfferVariant(sku: 'SKU-CMD', barcode: '5901234123422', price: 7.5, localQty: 2);

        $exitCode = Artisan::call('pigu:export-offers-xml', ['--all-valid' => true, '-v' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('Filter all-valid: yes', $output);
        $this->assertStringContainsString('Variants considered:', $output);
        $this->assertStringContainsString('Unique SKU count:', $output);
        $this->assertStringContainsString('Unique EAN count:', $output);
        $this->assertStringContainsString('Duplicate EAN count:', $output);
        $this->assertTrue(Storage::disk('public')->exists('exports/pigu_offers_failed_latest.csv'));
    }

    private function assertSkippedCsvContains(string $reason): void
    {
        $csv = Storage::disk('public')->get('exports/pigu_offers_failed_latest.csv');
        $this->assertStringContainsString('skip_reason', $csv);
        $this->assertStringContainsString($reason, $csv);
    }

    private function seedMinimalOfferVariant(
        string $sku,
        string $barcode,
        ?float $price,
        int $localQty,
        ?int $supplierQty = null,
        bool $backorder = false,
        bool $withMapping = false,
        bool $withImage = false,
        bool $withDescription = false,
        string $title = 'Offer',
        string $externalSuffix = '',
    ): Product {
        $source = Source::query()->firstOrCreate(
            ['type' => 'shopify'],
            ['name' => 'Shopify', 'enabled' => true, 'config' => []],
        );

        $channel = MarketplaceChannel::query()->firstOrCreate(
            ['type' => 'pigu'],
            ['name' => 'Pigu / PHH', 'enabled' => true, 'config' => ['require_category_mapping' => true]],
        );

        $suffix = $externalSuffix !== '' ? $externalSuffix : uniqid();

        if ($withMapping) {
            CategoryMapping::query()->firstOrCreate(
                [
                    'marketplace_channel_id' => $channel->id,
                    'source_type' => 'product_type',
                    'source_value' => 'AllValidType',
                ],
                [
                    'target_category_id' => '9001',
                    'target_category_name' => 'All Valid Cat',
                    'target_category_path' => '9001|All Valid Cat',
                    'priority' => 10,
                    'enabled' => true,
                    'export_enabled' => true,
                ],
            );
        }

        $product = Product::query()->create([
            'source_id' => $source->id,
            'external_id' => 'p-allvalid-'.$suffix,
            'title' => $title.' '.$sku,
            'description_html' => $withDescription ? '<p>Description</p>' : null,
            'vendor' => 'Vendor',
            'product_type' => $withMapping ? 'AllValidType' : 'UnmappedType',
            'status' => ProductStatus::Active,
            'handle' => 'allvalid-'.$suffix,
            'imported_at' => now(),
        ]);

        if ($withImage) {
            ProductImage::query()->create([
                'product_id' => $product->id,
                'url' => 'https://cdn.example.com/allvalid.jpg',
                'position' => 0,
            ]);
        }

        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'external_id' => 'v-allvalid-'.$suffix,
            'sku' => $sku === '' ? null : $sku,
            'barcode' => $barcode === '' ? null : $barcode,
            'title' => 'Default Title',
            'price' => $price,
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
                'name' => 'AllValid Supplier',
                'code' => 'allvalid-supplier-'.$suffix,
                'enabled' => true,
                'in_stock_delivery_text' => '5-10 d.d.',
                'stale_after_minutes' => 1800,
            ]);

            SupplierProduct::query()->create([
                'supplier_id' => $supplier->id,
                'product_variant_id' => $variant->id,
                'supplier_sku' => $sku !== '' ? $sku : 'NOSKU',
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
