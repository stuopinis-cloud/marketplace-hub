<?php

namespace Tests\Feature\Marketplace;

use App\Enums\FeedFileStatus;
use App\Enums\ProductStatus;
use App\Filament\Resources\PiguSupplierXml\Pages\PreviewPiguSupplierOffers;
use App\Jobs\GeneratePiguSupplierOfferPreviewJob;
use App\Models\CategoryMapping;
use App\Models\FeedFile;
use App\Models\InventoryLevel;
use App\Models\MarketplaceChannel;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Source;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Services\Marketplace\Pigu\PiguExportOptions;
use App\Services\Marketplace\Pigu\PiguOfferFeedExporter;
use App\Services\Marketplace\Pigu\PiguSupplierOfferConfig;
use App\Services\Marketplace\Pigu\PiguSupplierOfferPreviewService;
use App\Services\Marketplace\Pigu\PiguSupplierOfferPreviewStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PiguSupplierOfferPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');

        config([
            'marketplace.exports.pigu.local_collection_hours' => 24,
            'marketplace.exports.pigu.supplier_collection_hours' => 168,
            'marketplace.exports.pigu.backorder_collection_hours' => 240,
            'marketplace.exports.pigu.preview.timeout' => 1800,
            'marketplace.exports.pigu.preview.tries' => 1,
        ]);

        $this->ensurePiguChannel();
    }

    public function test_preview_page_does_not_generate_xml_synchronously(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $this->actingAs($user);

        $supplier = $this->createPiguXmlSupplier(['enabled' => false]);

        Livewire::test(PreviewPiguSupplierOffers::class, ['record' => $supplier->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Generuoti XML peržiūrą')
            ->assertSee('Nepaleista')
            ->assertDontSee('Generuojama…');

        Queue::assertNothingPushed();
        $this->assertFalse(Storage::disk('local')->exists(
            PiguOfferFeedExporter::previewDirectory($supplier->id).'/offers.xml'
        ));
    }

    public function test_generate_button_dispatches_single_job_and_blocks_duplicates(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $this->actingAs($user);

        $supplier = $this->createPiguXmlSupplier();

        Livewire::test(PreviewPiguSupplierOffers::class, ['record' => $supplier->getRouteKey()])
            ->callAction('generate')
            ->assertNotified();

        Queue::assertPushed(GeneratePiguSupplierOfferPreviewJob::class, 1);

        $status = app(PiguSupplierOfferPreviewStatus::class)->read($supplier->id);
        $this->assertSame(PiguSupplierOfferPreviewStatus::STATUS_PENDING, $status['status']);

        $dispatch = app(PiguSupplierOfferPreviewService::class)->dispatch($supplier);
        $this->assertFalse($dispatch['dispatched']);

        Queue::assertPushed(GeneratePiguSupplierOfferPreviewJob::class, 1);
    }

    public function test_queued_job_generates_correct_isolated_xml(): void
    {
        $supplier = $this->createPiguXmlSupplier(['enabled' => true]);

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'PREVIEW-1',
            'barcode' => '5901234567890',
            'price' => 10.00,
            'stock_quantity' => 5,
            'match_status' => SupplierProduct::MATCH_STATUS_UNMATCHED,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        Storage::disk('public')->put(PiguOfferFeedExporter::PRODUCTION_FEED_RELATIVE, '<products></products>');
        $productionBefore = Storage::disk('public')->get(PiguOfferFeedExporter::PRODUCTION_FEED_RELATIVE);

        (new GeneratePiguSupplierOfferPreviewJob($supplier->id))->handle(
            app(PiguSupplierOfferPreviewService::class),
            app(PiguSupplierOfferPreviewStatus::class),
        );

        $status = app(PiguSupplierOfferPreviewStatus::class)->read($supplier->id);
        $this->assertSame(PiguSupplierOfferPreviewStatus::STATUS_READY, $status['status']);
        $this->assertSame(1, $status['stats']['valid_offers'] ?? null);

        $xml = Storage::disk('local')->get(PiguOfferFeedExporter::previewDirectory($supplier->id).'/offers.xml');
        $this->assertStringContainsString('<sku><![CDATA[PREVIEW-1]]></sku>', $xml);
        $this->assertStringContainsString('<price-after-discount>12.10</price-after-discount>', $xml);
        $this->assertSame($productionBefore, Storage::disk('public')->get(PiguOfferFeedExporter::PRODUCTION_FEED_RELATIVE));
        $this->assertSame(0, FeedFile::query()->count());
    }

    public function test_preview_includes_disabled_pigu_supplier_without_saving_activation(): void
    {
        $supplier = $this->createPiguXmlSupplier(['enabled' => false]);

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'DISABLED-1',
            'barcode' => '5901234567892',
            'price' => 20.00,
            'stock_quantity' => 3,
            'match_status' => SupplierProduct::MATCH_STATUS_UNMATCHED,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        $preview = app(PiguSupplierOfferPreviewService::class)->generate($supplier);
        $xml = Storage::disk('local')->get($preview['feed_path']);

        $this->assertSame(1, $preview['valid_offers']);
        $this->assertStringContainsString('<sku><![CDATA[DISABLED-1]]></sku>', $xml);
        $this->assertFalse(PiguSupplierOfferConfig::isEnabled($supplier->fresh()));
    }

    public function test_preview_respects_local_stock_priority_and_shopify_price(): void
    {
        $variant = $this->seedShopifyVariant(
            sku: 'SHARED-PREVIEW',
            barcode: '5901234567001',
            price: 29.50,
            localQty: 4,
        );

        $supplier = $this->createPiguXmlSupplier();
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_variant_id' => $variant->id,
            'supplier_sku' => 'SUP-SHARED',
            'barcode' => '5901234567001',
            'price' => 99.00,
            'stock_quantity' => 50,
            'match_status' => SupplierProduct::MATCH_STATUS_MATCHED,
            'match_method' => SupplierProduct::MATCH_METHOD_BARCODE,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        $preview = app(PiguSupplierOfferPreviewService::class)->generate($supplier);
        $xml = Storage::disk('local')->get($preview['feed_path']);

        $this->assertSame(0, $preview['valid_offers']);
        $this->assertStringNotContainsString('SHARED-PREVIEW', $xml);
        $this->assertStringNotContainsString('99.00', $xml);
    }

    public function test_preview_includes_supplier_stock_when_local_is_zero(): void
    {
        $variant = $this->seedShopifyVariant(
            sku: 'SUP-STOCK',
            barcode: '5901234567002',
            price: 15.00,
            localQty: 0,
        );

        $supplier = $this->createPiguXmlSupplier();
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_variant_id' => $variant->id,
            'supplier_sku' => 'SUP-STOCK-X',
            'barcode' => '5901234567002',
            'price' => 8.00,
            'stock_quantity' => 12,
            'match_status' => SupplierProduct::MATCH_STATUS_MATCHED,
            'match_method' => SupplierProduct::MATCH_METHOD_BARCODE,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        $preview = app(PiguSupplierOfferPreviewService::class)->generate($supplier);
        $xml = Storage::disk('local')->get($preview['feed_path']);

        $this->assertSame(1, $preview['shopify_matched_offers']);
        $this->assertStringContainsString('<sku><![CDATA[SUP-STOCK]]></sku>', $xml);
        $this->assertStringContainsString('<price-after-discount>15.00</price-after-discount>', $xml);
        $this->assertStringContainsString('<stock>12</stock>', $xml);
        $this->assertStringContainsString('<collectionhours>240</collectionhours>', $xml);
    }

    public function test_preview_deduplicates_against_existing_shopify_ean(): void
    {
        $this->seedShopifyVariant(
            sku: 'SHOP-EAN',
            barcode: '5901234567010',
            price: 11.00,
            localQty: 2,
        );

        $supplier = $this->createPiguXmlSupplier();
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'DUP-EAN',
            'barcode' => '5901234567010',
            'price' => 5.00,
            'stock_quantity' => 9,
            'match_status' => SupplierProduct::MATCH_STATUS_UNMATCHED,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        $preview = app(PiguSupplierOfferPreviewService::class)->generate($supplier);

        $this->assertSame(0, $preview['valid_offers']);
        $this->assertSame(1, $preview['deduplicated_offers']);
        $this->assertSame(1, $preview['skipped_by_reason']['duplicate_ean'] ?? 0);
    }

    public function test_preview_xml_matches_production_contribution_for_identical_inputs(): void
    {
        $supplier = $this->createPiguXmlSupplier(['enabled' => true]);

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'EQ-1',
            'barcode' => '5901234567888',
            'price' => 40.00,
            'stock_quantity' => 7,
            'match_status' => SupplierProduct::MATCH_STATUS_UNMATCHED,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        $preview = app(PiguSupplierOfferPreviewService::class)->generate($supplier);
        $previewXml = Storage::disk('local')->get($preview['feed_path']);

        $production = app(PiguOfferFeedExporter::class)->export(new PiguExportOptions(allValid: true));
        $productionXml = Storage::disk('public')->get($production['feed_path']);

        $this->assertSame($previewXml, $productionXml);
    }

    public function test_preview_export_does_not_retain_row_arrays_in_result(): void
    {
        $supplier = $this->createPiguXmlSupplier();

        for ($i = 0; $i < 50; $i++) {
            SupplierProduct::query()->create([
                'supplier_id' => $supplier->id,
                'supplier_sku' => 'MEM-'.$i,
                'barcode' => sprintf('5901234567%03d', $i),
                'price' => 10.00,
                'stock_quantity' => 2,
                'match_status' => SupplierProduct::MATCH_STATUS_UNMATCHED,
                'enabled' => true,
                'last_synced_at' => now(),
            ]);
        }

        $before = memory_get_usage(true);
        $result = app(PiguOfferFeedExporter::class)->export(PiguExportOptions::supplierPreview($supplier->id));
        $after = memory_get_usage(true);

        $this->assertArrayNotHasKey('exported_rows', $result);
        $this->assertArrayNotHasKey('skipped_rows', $result);
        $this->assertLessThanOrEqual(8, count($result['examples'] ?? []));
        $this->assertLessThan(32 * 1024 * 1024, $after - $before);
    }

    public function test_production_export_still_updates_feed_file_and_public_path(): void
    {
        $supplier = $this->createPiguXmlSupplier();
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'PROD-1',
            'barcode' => '5901234567877',
            'price' => 10.00,
            'stock_quantity' => 2,
            'match_status' => SupplierProduct::MATCH_STATUS_UNMATCHED,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        app(PiguSupplierOfferPreviewService::class)->generate($supplier);
        $this->assertSame(0, FeedFile::query()->count());

        $result = app(PiguOfferFeedExporter::class)->export(new PiguExportOptions(allValid: true));

        $this->assertTrue(Storage::disk('public')->exists($result['feed_path']));
        $this->assertDatabaseHas('feed_files', [
            'filename' => 'pigu-offers.xml',
            'path' => 'feeds/pigu-offers.xml',
            'status' => FeedFileStatus::Generated->value,
        ]);
    }

    public function test_authenticated_user_can_download_ready_preview_files(): void
    {
        $supplier = $this->createPiguXmlSupplier();
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'DL-1',
            'barcode' => '5901234567866',
            'price' => 12.00,
            'stock_quantity' => 4,
            'match_status' => SupplierProduct::MATCH_STATUS_UNMATCHED,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        app(PiguSupplierOfferPreviewService::class)->generate($supplier);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('exports.pigu-supplier-preview.xml', $supplier))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('exports.pigu-supplier-preview.skipped', $supplier))
            ->assertOk();
    }

    public function test_download_before_ready_returns_conflict(): void
    {
        $supplier = $this->createPiguXmlSupplier();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('exports.pigu-supplier-preview.xml', $supplier))
            ->assertStatus(409);
    }

    public function test_guest_cannot_download_preview_files(): void
    {
        $supplier = $this->createPiguXmlSupplier();

        $this->get(route('exports.pigu-supplier-preview.xml', $supplier))
            ->assertRedirect();

        $this->get(route('exports.pigu-supplier-preview.skipped', $supplier))
            ->assertRedirect();
    }

    public function test_preview_respects_higher_priority_supplier_and_does_not_overstate(): void
    {
        $higher = $this->createPiguXmlSupplier(['enabled' => true]);
        $higher->update(['stock_priority' => 1, 'name' => 'Higher Priority']);

        $lower = $this->createPiguXmlSupplier(['enabled' => false]);
        $lower->update(['stock_priority' => 50, 'name' => 'Lower Priority']);

        foreach ([$higher, $lower] as $index => $supplier) {
            SupplierProduct::query()->create([
                'supplier_id' => $supplier->id,
                'supplier_sku' => 'PRIO-'.$index,
                'barcode' => '5901234567999',
                'price' => 10.00 + $index,
                'stock_quantity' => 5,
                'match_status' => SupplierProduct::MATCH_STATUS_UNMATCHED,
                'enabled' => true,
                'last_synced_at' => now(),
            ]);
        }

        $preview = app(PiguSupplierOfferPreviewService::class)->generate($lower);

        $this->assertSame(0, $preview['valid_offers']);
        $this->assertSame(1, $preview['deduplicated_offers']);
        $this->assertFalse(PiguSupplierOfferConfig::isEnabled($lower->fresh()));
    }

    private function ensurePiguChannel(): MarketplaceChannel
    {
        return MarketplaceChannel::query()->firstOrCreate(
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
            'name' => 'Preview XML Supplier',
            'code' => 'pigu-preview-'.uniqid(),
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
