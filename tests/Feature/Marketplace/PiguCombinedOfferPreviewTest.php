<?php

namespace Tests\Feature\Marketplace;

use App\Enums\FeedFileStatus;
use App\Enums\ProductStatus;
use App\Filament\Resources\PiguSupplierXml\Pages\PreviewCombinedPiguOffers;
use App\Filament\Resources\PiguSupplierXml\Pages\ViewPiguSupplierXml;
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

class PiguCombinedOfferPreviewTest extends TestCase
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
            'marketplace.exports.pigu.preview.diagnostic_skus' => ['10001001', '10183008'],
        ]);

        $this->ensurePiguChannel();
    }

    public function test_view_page_has_combined_preview_action(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $supplier = $this->createPiguXmlSupplier(['enabled' => false]);

        Livewire::test(ViewPiguSupplierXml::class, ['record' => $supplier->getRouteKey()])
            ->assertSuccessful()
            ->assertActionExists('previewCombinedPiguXml');
    }

    public function test_combined_preview_page_does_not_generate_synchronously(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $this->actingAs($user);

        $supplier = $this->createPiguXmlSupplier(['enabled' => false]);

        Livewire::test(PreviewCombinedPiguOffers::class, ['record' => $supplier->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Generuoti bendrą XML peržiūrą')
            ->assertSee('Nepaleista');

        Queue::assertNothingPushed();
    }

    public function test_combined_dispatch_is_unique_per_supplier(): void
    {
        Queue::fake();

        $supplier = $this->createPiguXmlSupplier(['enabled' => false]);
        $service = app(PiguSupplierOfferPreviewService::class);

        $first = $service->dispatch($supplier, PiguExportOptions::PREVIEW_SCOPE_COMBINED);
        $second = $service->dispatch($supplier, PiguExportOptions::PREVIEW_SCOPE_COMBINED);

        $this->assertTrue($first['dispatched']);
        $this->assertFalse($second['dispatched']);
        Queue::assertPushed(GeneratePiguSupplierOfferPreviewJob::class, 1);
    }

    public function test_combined_preview_includes_disabled_supplier_and_matches_enabled_production_parity(): void
    {
        $supplier = $this->createPiguXmlSupplier(['enabled' => false]);

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'ONLY-1',
            'barcode' => '5901234567801',
            'price' => 10.00,
            'stock_quantity' => 4,
            'match_status' => SupplierProduct::MATCH_STATUS_UNMATCHED,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        $preview = app(PiguSupplierOfferPreviewService::class)
            ->generate($supplier, PiguExportOptions::PREVIEW_SCOPE_COMBINED);

        $this->assertFalse(PiguSupplierOfferConfig::isEnabled($supplier->fresh()));
        $this->assertStringContainsString('/combined/', $preview['feed_path']);
        $this->assertSame(0, FeedFile::query()->count());
        $this->assertFalse(Storage::disk('public')->exists(PiguOfferFeedExporter::PRODUCTION_FEED_RELATIVE));

        // Enable and export production — should match the combined preview XML.
        $config = $supplier->config;
        data_set($config, PiguSupplierOfferConfig::CONFIG_KEY.'.enabled', true);
        $supplier->update(['config' => $config]);

        $production = app(PiguOfferFeedExporter::class)->export(new PiguExportOptions(allValid: true));
        $previewXml = Storage::disk('local')->get($preview['feed_path']);
        $productionXml = Storage::disk('public')->get($production['feed_path']);

        $this->assertSame($previewXml, $productionXml);
        $this->assertDatabaseHas('feed_files', [
            'filename' => 'pigu-offers.xml',
            'status' => FeedFileStatus::Generated->value,
        ]);
    }

    public function test_combined_preview_priority_and_diagnostics_for_key_skus(): void
    {
        $supplier = $this->createPiguXmlSupplier(['enabled' => false]);

        // SKU 10001001: local stock > 0 => stock 2 / 24h / Shopify price
        $localVariant = $this->seedShopifyVariant('10001001', '5901234567101', 19.99, 2);
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_variant_id' => $localVariant->id,
            'supplier_sku' => 'MTAC-10001001',
            'barcode' => '5901234567101',
            'price' => 99.00,
            'stock_quantity' => 50,
            'match_status' => SupplierProduct::MATCH_STATUS_MATCHED,
            'match_method' => SupplierProduct::MATCH_METHOD_BARCODE,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        // SKU 10183008: local 0 + supplier stock => stock 20 / 240h / Shopify price
        $supplierStockVariant = $this->seedShopifyVariant('10183008', '5901234567102', 45.50, 0);
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'product_variant_id' => $supplierStockVariant->id,
            'supplier_sku' => 'MTAC-10183008',
            'barcode' => '5901234567102',
            'price' => 12.00,
            'stock_quantity' => 20,
            'match_status' => SupplierProduct::MATCH_STATUS_MATCHED,
            'match_method' => SupplierProduct::MATCH_METHOD_BARCODE,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        $preview = app(PiguSupplierOfferPreviewService::class)
            ->generate($supplier, PiguExportOptions::PREVIEW_SCOPE_COMBINED);

        $xml = Storage::disk('local')->get($preview['feed_path']);
        $diagnostics = $preview['diagnostics'] ?? [];

        $this->assertSame(2, $diagnostics['10001001']['stock'] ?? null);
        $this->assertSame(24, $diagnostics['10001001']['collectionhours'] ?? null);
        $this->assertSame('local', $diagnostics['10001001']['hours_source'] ?? null);
        $this->assertSame(19.99, (float) ($diagnostics['10001001']['price'] ?? 0));

        $this->assertSame(20, $diagnostics['10183008']['stock'] ?? null);
        $this->assertSame(240, $diagnostics['10183008']['collectionhours'] ?? null);
        $this->assertSame('supplier', $diagnostics['10183008']['hours_source'] ?? null);
        $this->assertSame(45.50, (float) ($diagnostics['10183008']['price'] ?? 0));

        $this->assertStringContainsString('<sku><![CDATA[10001001]]></sku>', $xml);
        $this->assertStringContainsString('<collectionhours>24</collectionhours>', $xml);
        $this->assertStringContainsString('<sku><![CDATA[10183008]]></sku>', $xml);
        $this->assertStringContainsString('<stock>20</stock>', $xml);
        $this->assertFalse(PiguSupplierOfferConfig::isEnabled($supplier->fresh()));
    }

    public function test_combined_preview_comparison_against_public_feed(): void
    {
        $supplier = $this->createPiguXmlSupplier(['enabled' => true]);

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'BASE-1',
            'barcode' => '5901234567201',
            'price' => 10.00,
            'stock_quantity' => 3,
            'match_status' => SupplierProduct::MATCH_STATUS_UNMATCHED,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        // Current public feed without the supplier-only offer.
        Storage::disk('public')->put(
            PiguOfferFeedExporter::PRODUCTION_FEED_RELATIVE,
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<products>\n</products>\n",
        );

        $preview = app(PiguSupplierOfferPreviewService::class)
            ->generate($supplier, PiguExportOptions::PREVIEW_SCOPE_COMBINED);

        $this->assertSame(1, $preview['comparison']['offers_added'] ?? null);
        $this->assertSame(0, $preview['comparison']['offers_removed'] ?? null);
        $this->assertSame(1, $preview['comparison']['preview_offers'] ?? null);
        $this->assertSame(0, $preview['comparison']['baseline_offers'] ?? null);
    }

    public function test_combined_job_writes_private_artifacts_only(): void
    {
        $supplier = $this->createPiguXmlSupplier(['enabled' => false]);
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'JOB-1',
            'barcode' => '5901234567301',
            'price' => 8.00,
            'stock_quantity' => 2,
            'match_status' => SupplierProduct::MATCH_STATUS_UNMATCHED,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        Storage::disk('public')->put(PiguOfferFeedExporter::PRODUCTION_FEED_RELATIVE, '<products></products>');
        $before = Storage::disk('public')->get(PiguOfferFeedExporter::PRODUCTION_FEED_RELATIVE);

        (new GeneratePiguSupplierOfferPreviewJob(
            $supplier->id,
            PiguExportOptions::PREVIEW_SCOPE_COMBINED,
        ))->handle(
            app(PiguSupplierOfferPreviewService::class),
            app(PiguSupplierOfferPreviewStatus::class),
        );

        $status = app(PiguSupplierOfferPreviewStatus::class)->read(
            $supplier->id,
            PiguExportOptions::PREVIEW_SCOPE_COMBINED,
        );

        $this->assertSame(PiguSupplierOfferPreviewStatus::STATUS_READY, $status['status']);
        $this->assertTrue(Storage::disk('local')->exists(
            PiguOfferFeedExporter::previewDirectory($supplier->id, PiguExportOptions::PREVIEW_SCOPE_COMBINED).'/offers.xml'
        ));
        $this->assertSame($before, Storage::disk('public')->get(PiguOfferFeedExporter::PRODUCTION_FEED_RELATIVE));
        $this->assertSame(0, FeedFile::query()->count());
    }

    public function test_combined_download_requires_auth_and_ready_status(): void
    {
        $supplier = $this->createPiguXmlSupplier(['enabled' => false]);
        $user = User::factory()->create();

        $this->get(route('exports.pigu-supplier-preview.combined.xml', $supplier))
            ->assertRedirect();

        $this->actingAs($user)
            ->get(route('exports.pigu-supplier-preview.combined.xml', $supplier))
            ->assertStatus(409);

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'DL-C',
            'barcode' => '5901234567401',
            'price' => 7.00,
            'stock_quantity' => 1,
            'match_status' => SupplierProduct::MATCH_STATUS_UNMATCHED,
            'enabled' => true,
            'last_synced_at' => now(),
        ]);

        app(PiguSupplierOfferPreviewService::class)
            ->generate($supplier, PiguExportOptions::PREVIEW_SCOPE_COMBINED);

        $this->actingAs($user)
            ->get(route('exports.pigu-supplier-preview.combined.xml', $supplier))
            ->assertOk();
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
            'name' => 'M-Tac Preview Supplier',
            'code' => 'mtac-preview-'.uniqid(),
            'enabled' => true,
            'connector_type' => Supplier::CONNECTOR_XML_URL,
            'endpoint_url' => 'https://example.test/mtac.xml',
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
