<?php

namespace Tests\Unit\Services\Marketplace\Varle;

use App\Enums\FeedFileStatus;
use App\Enums\SyncJobItemStatus;
use App\Models\CategoryMapping;
use App\Models\FeedFile;
use App\Models\MarketplaceChannel;
use App\Models\Product;
use App\Models\Source;
use App\Models\SourceCategory;
use App\Models\SyncJob;
use App\Models\SyncJobItem;
use App\Services\Marketplace\CategoryResolver;
use App\Services\Marketplace\Varle\VarleProductValidator;
use App\Services\Marketplace\Varle\VarleXmlExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\VarleCatalogFixtures;
use Tests\TestCase;

class VarleXmlExporterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config([
            'marketplace.exports.varle.store_url' => 'https://ebunkeris.lt',
        ]);
    }

    public function test_single_variant_product_exports_product_level_quantity_and_barcode(): void
    {
        VarleCatalogFixtures::createExportableVariant(
            productOverrides: [
                'title' => 'Sneakers',
                'handle' => 'sneakers',
                'description_html' => '<p>Comfortable <strong>shoes</strong></p>',
                'raw_payload' => ['options' => []],
            ],
            variantOverrides: [
                'sku' => 'SKU-100',
                'barcode' => '5901234123457',
                'price' => 29.5,
                'option1' => null,
                'option2' => null,
                'option1_name' => null,
                'option1_value' => null,
                'option2_name' => null,
                'option2_value' => null,
                'raw_payload' => [],
            ],
        );

        $result = $this->makeExporter()->export();
        $xml = Storage::disk('public')->get('feeds/varle.xml');

        $this->assertSame(1, $result->exportedVariants);
        $this->assertSame(0, $result->skippedVariants);
        $this->assertSame(1, substr_count($xml, '<product>'));
        $this->assertStringContainsString('<id>sneakers</id>', $xml);
        $this->assertStringContainsString('<model><![CDATA[SKU-100]]></model>', $xml);
        $this->assertStringContainsString('<title><![CDATA[Sneakers]]></title>', $xml);
        $this->assertStringNotContainsString('Sneakers -', $xml);
        $this->assertStringContainsString('<price>29.50</price>', $xml);
        $this->assertStringContainsString('<prime_costs>24.38</prime_costs>', $xml);
        $this->assertStringContainsString('<manufacturer><![CDATA[Vendor Name]]></manufacturer>', $xml);
        $this->assertStringNotContainsString('<delivery_text>', $xml);
        $this->assertStringContainsString('<quantity>5</quantity>', $xml);
        $this->assertStringContainsString('<barcode>5901234123457</barcode>', $xml);
        $this->assertStringContainsString('<categories>', $xml);
        $this->assertStringContainsString('<category><![CDATA[Footwear]]></category>', $xml);
        $this->assertStringContainsString('<images>', $xml);
        $this->assertStringContainsString('<image><![CDATA[https://cdn.example.com/image.jpg]]></image>', $xml);
        $this->assertStringContainsString('<description><![CDATA[<p>Comfortable <strong>shoes</strong></p>]]></description>', $xml);
        $this->assertStringContainsString('<url><![CDATA[https://ebunkeris.lt/products/sneakers]]></url>', $xml);
        $this->assertStringContainsString('<group><![CDATA[sneakers]]></group>', $xml);
        $this->assertStringNotContainsString('<variants>', $xml);
        $this->assertTrue((bool) simplexml_load_string($xml));
    }

    public function test_multi_variant_product_with_color_and_size_exports_sizes_inside_variants(): void
    {
        VarleCatalogFixtures::createMultiVariantProduct();

        $this->makeExporter()->export();
        $xml = Storage::disk('public')->get('feeds/varle.xml');

        $this->assertSame(1, substr_count($xml, '<product>'));
        $this->assertStringContainsString('<id>multi-variant-product-ral7013</id>', $xml);
        $this->assertStringContainsString('<model><![CDATA[SKU-S]]></model>', $xml);
        $this->assertStringContainsString('<title><![CDATA[Multi Variant Product, RAL7013]]></title>', $xml);
        $this->assertStringContainsString('<price>20.00</price>', $xml);
        $this->assertStringContainsString('<prime_costs>16.53</prime_costs>', $xml);
        $this->assertStringContainsString('<variants>', $xml);
        $this->assertStringContainsString('group_title="Dydis"', $xml);
        $this->assertStringContainsString('<title>S</title>', $xml);
        $this->assertStringContainsString('<title>L</title>', $xml);
        $this->assertStringNotContainsString('<title>RAL7013 / S</title>', $xml);
        $this->assertStringContainsString('<group><![CDATA[multi-variant-product]]></group>', $xml);
        $this->assertStringNotContainsString('<delivery_text>', $xml);
    }

    public function test_product_with_color_and_size_exports_one_product_per_color(): void
    {
        VarleCatalogFixtures::createColorSizeProduct();

        $result = $this->makeExporter()->export();
        $xml = Storage::disk('public')->get('feeds/varle.xml');

        $this->assertSame(3, $result->exportedVariants);
        $this->assertSame(2, substr_count($xml, '<product>'));
        $this->assertStringContainsString('<id>vyriski-marskiniai-k459-melyni</id>', $xml);
        $this->assertStringContainsString('<id>vyriski-marskiniai-k459-juodi</id>', $xml);
        $this->assertStringContainsString('<title><![CDATA[Vyriški marškiniai K459, Mėlyni]]></title>', $xml);
        $this->assertStringContainsString('<title><![CDATA[Vyriški marškiniai K459, Juodi]]></title>', $xml);
        $this->assertStringContainsString('<group><![CDATA[vyriski-marskiniai-k459]]></group>', $xml);
        $this->assertStringContainsString('<manufacturer><![CDATA[Shopify Vendor]]></manufacturer>', $xml);
        $this->assertStringContainsString('group_title="Dydis"', $xml);
        $this->assertSame(2, substr_count($xml, '<variants>'));
    }

    public function test_color_group_with_single_size_still_outputs_variants(): void
    {
        VarleCatalogFixtures::createColorSizeProduct();

        $this->makeExporter()->export();
        $xml = Storage::disk('public')->get('feeds/varle.xml');

        $juodiSection = $this->extractProductXmlSection($xml, 'vyriski-marskiniai-k459-juodi');

        $this->assertStringContainsString('<title><![CDATA[Vyriški marškiniai K459, Juodi]]></title>', $juodiSection);
        $this->assertStringContainsString('<variants>', $juodiSection);
        $this->assertStringContainsString('<title>M</title>', $juodiSection);
        $this->assertStringNotContainsString('Juodi / M', $juodiSection);
        $this->assertStringNotContainsString('<barcode>4770000000003</barcode>', preg_replace('/<variants>.*?<\/variants>/s', '', $juodiSection));
    }

    public function test_color_only_exports_product_level_quantity_and_barcode(): void
    {
        VarleCatalogFixtures::createColorOnlyProduct();

        $this->makeExporter()->export();
        $xml = Storage::disk('public')->get('feeds/varle.xml');

        $this->assertSame(2, substr_count($xml, '<product>'));
        $this->assertStringContainsString('<title><![CDATA[Product title, Juoda]]></title>', $xml);
        $this->assertStringContainsString('<title><![CDATA[Product title, Žalia]]></title>', $xml);
        $this->assertStringNotContainsString('<variants>', $xml);

        $juodaSection = $this->extractProductXmlSection($xml, 'color-only-product-juoda');
        $this->assertStringContainsString('<quantity>1</quantity>', $juodaSection);
        $this->assertStringContainsString('<barcode>4770000000101</barcode>', $juodaSection);
    }

    public function test_size_only_exports_one_product_with_variants(): void
    {
        VarleCatalogFixtures::createSizeOnlyProduct();

        $this->makeExporter()->export();
        $xml = Storage::disk('public')->get('feeds/varle.xml');

        $this->assertSame(1, substr_count($xml, '<product>'));
        $this->assertStringContainsString('<id>size-only-product</id>', $xml);
        $this->assertStringContainsString('<title><![CDATA[Size Only Product]]></title>', $xml);
        $this->assertStringContainsString('<variants>', $xml);
        $this->assertStringContainsString('group_title="Dydis"', $xml);
        $this->assertStringContainsString('<title>S</title>', $xml);
        $this->assertStringContainsString('<title>M</title>', $xml);
    }

    public function test_non_color_extra_option_remains_inside_variants(): void
    {
        VarleCatalogFixtures::createMultiNonColorOptionProduct();

        $this->makeExporter()->export();
        $xml = Storage::disk('public')->get('feeds/varle.xml');

        $this->assertStringContainsString('group_title="Dydis / Pusė"', $xml);
        $this->assertStringContainsString('<title>M / Kairė</title>', $xml);
        $this->assertStringContainsString('<variants>', $xml);
    }

    public function test_exporter_splits_mechanix_gloves_by_color_using_option_name_columns(): void
    {
        VarleCatalogFixtures::createMechanixGlovesProduct();

        $this->makeExporter()->export();
        $xml = Storage::disk('public')->get('feeds/varle.xml');

        $this->assertSame(2, substr_count($xml, '<product>'));
        $this->assertStringContainsString(
            '<id>taktines-ziemines-pirstines-mechanix-coldwork-fastfit-coyote</id>',
            $xml,
        );
        $this->assertStringContainsString(
            '<id>taktines-ziemines-pirstines-mechanix-coldwork-fastfit-juoda</id>',
            $xml,
        );
        $this->assertStringContainsString(
            '<title><![CDATA[Taktinės žieminės pirštinės Mechanix ColdWork FastFit, Coyote]]></title>',
            $xml,
        );
        $this->assertStringContainsString(
            '<title><![CDATA[Taktinės žieminės pirštinės Mechanix ColdWork FastFit, Juoda]]></title>',
            $xml,
        );
        $this->assertStringContainsString(
            '<group><![CDATA[taktines-ziemines-pirstines-mechanix-coldwork-fastfit]]></group>',
            $xml,
        );

        $coyoteSection = $this->extractProductXmlSection(
            $xml,
            'taktines-ziemines-pirstines-mechanix-coldwork-fastfit-coyote',
        );

        $this->assertStringContainsString('<variants>', $coyoteSection);
        $this->assertStringContainsString('group_title="Dydis"', $coyoteSection);
        $this->assertStringContainsString('<title>S</title>', $coyoteSection);
        $this->assertStringContainsString('<title>M</title>', $coyoteSection);
        $this->assertStringNotContainsString('Coyote / S', $coyoteSection);
        $this->assertStringNotContainsString('<title>Coyote</title>', $coyoteSection);
    }

    public function test_exporter_splits_by_color_using_variant_option_name_columns(): void
    {
        VarleCatalogFixtures::createColorSizeProduct([
            'raw_payload' => [],
        ]);

        $this->makeExporter()->export();
        $xml = Storage::disk('public')->get('feeds/varle.xml');

        $this->assertSame(2, substr_count($xml, '<product>'));
        $this->assertStringContainsString('<id>vyriski-marskiniai-k459-melyni</id>', $xml);
        $this->assertStringContainsString('<id>vyriski-marskiniai-k459-juodi</id>', $xml);
        $this->assertStringContainsString('<title><![CDATA[Vyriški marškiniai K459, Mėlyni]]></title>', $xml);
        $this->assertStringContainsString('group_title="Dydis"', $xml);
        $this->assertStringNotContainsString('<title>Mėlyni / M</title>', $xml);
    }

    public function test_color_group_with_no_valid_variants_is_skipped(): void
    {
        VarleCatalogFixtures::createColorSizeProduct(variantDefinitions: [
            [
                'sku' => 'SKU-MELYNI',
                'barcode' => null,
                'price' => 20,
                'option1' => 'Mėlyni',
                'option2' => 'M',
                'raw_payload' => [
                    'selectedOptions' => [
                        ['name' => 'Spalva', 'value' => 'Mėlyni'],
                        ['name' => 'Dydis', 'value' => 'M'],
                    ],
                ],
            ],
            [
                'sku' => 'SKU-JUODI',
                'barcode' => '4770000000003',
                'price' => 22,
                'option1' => 'Juodi',
                'option2' => 'M',
                'raw_payload' => [
                    'selectedOptions' => [
                        ['name' => 'Spalva', 'value' => 'Juodi'],
                        ['name' => 'Dydis', 'value' => 'M'],
                    ],
                ],
            ],
        ]);

        $this->makeExporter()->export();

        $this->assertNotNull(
            SyncJobItem::query()
                ->where('message', 'Missing barcode')
                ->first(),
        );
        $this->assertStringContainsString('<id>vyriski-marskiniai-k459-juodi</id>', Storage::disk('public')->get('feeds/varle.xml'));
        $this->assertStringNotContainsString('<id>vyriski-marskiniai-k459-melyni</id>', Storage::disk('public')->get('feeds/varle.xml'));
    }

    public function test_missing_barcode_variant_is_skipped_and_logged(): void
    {
        VarleCatalogFixtures::createExportableVariant(
            productOverrides: ['handle' => 'valid-product'],
            variantOverrides: ['sku' => 'VALID-SKU', 'barcode' => '4770000000001'],
        );

        VarleCatalogFixtures::createExportableVariant(
            productOverrides: ['handle' => 'invalid-product'],
            variantOverrides: ['sku' => 'NO-BARCODE-SKU', 'barcode' => null],
        );

        $result = $this->makeExporter()->export();

        $this->assertSame(1, $result->exportedVariants);
        $this->assertSame(1, $result->skippedVariants);

        $failedItem = SyncJobItem::query()
            ->where('message', 'Missing barcode')
            ->first();

        $this->assertNotNull($failedItem);
        $this->assertSame('NO-BARCODE-SKU', $failedItem->sku);
        $this->assertNotNull($failedItem->variant_id);
    }

    public function test_product_with_all_variants_missing_barcode_is_skipped(): void
    {
        VarleCatalogFixtures::createMultiVariantProduct(variantDefinitions: [
            ['sku' => 'SKU-1', 'barcode' => null, 'price' => 10],
            ['sku' => 'SKU-2', 'barcode' => null, 'price' => 12],
        ]);

        $result = $this->makeExporter()->export();
        $xml = Storage::disk('public')->get('feeds/varle.xml');

        $this->assertSame(0, $result->exportedVariants);
        $this->assertSame(2, $result->skippedVariants);
        $this->assertStringNotContainsString('<product>', $xml);

        $this->assertNotNull(
            SyncJobItem::query()
                ->where('message', 'Product skipped because all variants are invalid or missing barcode')
                ->first(),
        );
    }

    public function test_exporter_skips_invalid_variants_and_logs_sync_job_items(): void
    {
        VarleCatalogFixtures::createExportableVariant(variantOverrides: ['sku' => 'VALID-SKU']);
        VarleCatalogFixtures::createExportableVariant(variantOverrides: ['sku' => null, 'barcode' => null]);

        $result = $this->makeExporter()->export();

        $this->assertSame(1, $result->exportedVariants);
        $this->assertSame(1, $result->skippedVariants);

        $syncJob = SyncJob::query()->findOrFail($result->syncJobId);
        $this->assertSame(2, $syncJob->total_items);
        $this->assertSame(1, $syncJob->success_items);
        $this->assertSame(1, $syncJob->failed_items);
    }

    public function test_exporter_creates_marketplace_channel_feed_file_and_sync_job(): void
    {
        VarleCatalogFixtures::createExportableVariant();

        $result = $this->makeExporter()->export();

        $channel = MarketplaceChannel::query()->where('type', 'varle')->first();
        $this->assertNotNull($channel);

        $this->assertDatabaseHas('feed_files', [
            'marketplace_channel_id' => $channel->id,
            'filename' => 'varle.xml',
            'path' => 'feeds/varle.xml',
            'status' => FeedFileStatus::Generated->value,
        ]);

        $syncJob = SyncJob::query()->findOrFail($result->syncJobId);
        $this->assertSame('export', $syncJob->type);
        $this->assertSame('feeds/varle.xml', $syncJob->context['feed_path'] ?? null);
    }

    public function test_exporter_applies_price_multiplier(): void
    {
        VarleCatalogFixtures::createExportableVariant(variantOverrides: [
            'sku' => 'MULTI-SKU',
            'price' => 10,
        ]);

        MarketplaceChannel::query()->updateOrCreate(
            ['type' => 'varle', 'name' => 'Varle.lt'],
            [
                'enabled' => true,
                'config' => [
                    'delivery_text' => '1-2 d.d.',
                    'default_category' => 'Kita',
                    'export_zero_stock' => true,
                    'price_multiplier' => 1.5,
                    'feed_filename' => 'varle.xml',
                ],
            ],
        );

        $this->makeExporter()->export();

        $xml = Storage::disk('public')->get('feeds/varle.xml');
        $this->assertStringContainsString('<price>15.00</price>', $xml);
    }

    public function test_exporter_processes_products_in_configured_chunks(): void
    {
        config(['marketplace.exports.varle.export_chunk_size' => 2]);

        for ($index = 1; $index <= 5; $index++) {
            VarleCatalogFixtures::createExportableVariant(
                productOverrides: ['handle' => 'product-'.$index],
                variantOverrides: ['sku' => 'CHUNK-SKU-'.$index],
            );
        }

        $exporter = new class(
            $this->app->make(VarleProductValidator::class),
            $this->app->make(CategoryResolver::class),
            $this->app->make(\App\Services\Marketplace\Varle\VarleExportGatekeeper::class),
        ) extends VarleXmlExporter
        {
            public int $batchCount = 0;

            protected function chunkProducts(int $chunkSize, callable $callback): void
            {
                $this->productsQuery()->chunkById($chunkSize, function ($products) use ($callback): void {
                    $this->batchCount++;
                    $callback($products);
                });
            }
        };

        $result = $exporter->export();

        $this->assertSame(5, $result->exportedVariants);
        $this->assertSame(3, $exporter->batchCount);
    }

    public function test_exporter_does_not_load_all_products_with_get(): void
    {
        VarleCatalogFixtures::createExportableVariant();

        $exporter = new class(
            $this->app->make(VarleProductValidator::class),
            $this->app->make(CategoryResolver::class),
            $this->app->make(\App\Services\Marketplace\Varle\VarleExportGatekeeper::class),
        ) extends VarleXmlExporter
        {
            protected function productsQuery(): \Illuminate\Database\Eloquent\Builder
            {
                $query = parent::productsQuery();

                $query->macro('get', function (): void {
                    throw new \RuntimeException('Product::get() must not be used during Varle export.');
                });

                return $query;
            }
        };

        $exporter->export();

        $this->assertTrue(true);
    }

    public function test_exporter_uses_resolved_category_path_from_mapping(): void
    {
        $channel = MarketplaceChannel::query()->updateOrCreate(
            ['type' => 'varle', 'name' => 'Varle.lt'],
            [
                'enabled' => true,
                'config' => [
                    'delivery_text' => '1-2 d.d.',
                    'export_zero_stock' => true,
                    'price_multiplier' => 1,
                    'feed_filename' => 'varle.xml',
                    'require_category_mapping' => false,
                ],
            ],
        );

        $variant = VarleCatalogFixtures::createExportableVariant([
            'category' => 'Pirštinės',
            'product_type' => 'Pirštinės',
        ]);

        $source = Source::query()->firstOrFail();
        $sourceCategory = SourceCategory::query()->create([
            'source_id' => $source->id,
            'type' => 'collection',
            'name' => 'Pirštinės',
            'handle' => 'pirstines',
        ]);
        $variant->product->sourceCategories()->sync([$sourceCategory->id]);

        CategoryMapping::query()->create([
            'marketplace_channel_id' => $channel->id,
            'source_type' => 'collection',
            'source_value' => 'Pirštinės',
            'target_category_path' => 'Apranga, avalynė, aksesuarai → Pirštinės → Vyriškos pirštinės',
            'priority' => 100,
            'enabled' => true,
        ]);

        $this->makeExporter()->export();

        $xml = Storage::disk('public')->get('feeds/varle.xml');
        $this->assertStringContainsString(
            '<category><![CDATA[Apranga, avalynė, aksesuarai → Pirštinės → Vyriškos pirštinės]]></category>',
            $xml,
        );
        $this->assertStringNotContainsString('<category><![CDATA[Pirštinės]]></category>', $xml);
    }

    public function test_exporter_logs_warning_when_fallback_category_is_used(): void
    {
        MarketplaceChannel::query()->updateOrCreate(
            ['type' => 'varle', 'name' => 'Varle.lt'],
            [
                'enabled' => true,
                'config' => [
                    'delivery_text' => '1-2 d.d.',
                    'export_zero_stock' => true,
                    'price_multiplier' => 1,
                    'feed_filename' => 'varle.xml',
                    'require_category_mapping' => false,
                ],
            ],
        );

        VarleCatalogFixtures::createExportableVariant([
            'category' => null,
            'product_type' => 'Fallback Type',
        ]);

        $result = $this->makeExporter()->export();

        $syncJob = SyncJob::query()->findOrFail($result->syncJobId);
        $warnings = $syncJob->context['warnings'] ?? [];

        $this->assertNotEmpty($warnings);
        $this->assertTrue(
            collect($warnings)->contains(
                fn (string $warning): bool => str_contains($warning, 'No category mapping found, using fallback category: Fallback Type'),
            ),
        );
    }

    public function test_exporter_skips_product_when_required_mapping_is_missing(): void
    {
        MarketplaceChannel::query()->updateOrCreate(
            ['type' => 'varle', 'name' => 'Varle.lt'],
            [
                'enabled' => true,
                'config' => [
                    'delivery_text' => '1-2 d.d.',
                    'export_zero_stock' => true,
                    'price_multiplier' => 1,
                    'feed_filename' => 'varle.xml',
                    'require_category_mapping' => true,
                ],
            ],
        );

        VarleCatalogFixtures::createExportableVariant([
            'category' => 'Should Not Export',
            'product_type' => 'Also Ignored',
        ]);

        $result = $this->makeExporter()->export();

        $this->assertSame(0, $result->exportedVariants);

        $failedItem = SyncJobItem::query()->firstOrFail();
        $this->assertStringContainsString('Missing required category mapping', (string) $failedItem->message);
    }

    public function test_exporter_skips_product_when_category_cannot_be_resolved(): void
    {
        MarketplaceChannel::query()->updateOrCreate(
            ['type' => 'varle', 'name' => 'Varle.lt'],
            [
                'enabled' => true,
                'config' => [
                    'delivery_text' => '1-2 d.d.',
                    'export_zero_stock' => true,
                    'price_multiplier' => 1,
                    'feed_filename' => 'varle.xml',
                    'require_category_mapping' => false,
                ],
            ],
        );

        VarleCatalogFixtures::createExportableVariant([
            'category' => null,
            'product_type' => null,
        ]);

        $result = $this->makeExporter()->export();

        $this->assertSame(0, $result->exportedVariants);
        $this->assertGreaterThan(0, $result->skippedVariants);

        $failedItem = SyncJobItem::query()->firstOrFail();
        $this->assertStringContainsString('Category could not be resolved.', (string) $failedItem->message);
        $this->assertStringNotContainsString('<product>', Storage::disk('public')->get('feeds/varle.xml'));
    }

    public function test_color_split_product_uses_only_variant_images_from_color_group(): void
    {
        VarleCatalogFixtures::createColorSizeProduct();

        $this->makeExporter()->export();
        $xml = Storage::disk('public')->get('feeds/varle.xml');

        $melyniSection = $this->extractProductXmlSection($xml, 'vyriski-marskiniai-k459-melyni');
        $juodiSection = $this->extractProductXmlSection($xml, 'vyriski-marskiniai-k459-juodi');

        $this->assertStringContainsString('<image><![CDATA[https://cdn.example.com/melyni.jpg]]></image>', $melyniSection);
        $this->assertStringNotContainsString('juodi.jpg', $melyniSection);
        $this->assertStringContainsString('<image><![CDATA[https://cdn.example.com/juodi.jpg]]></image>', $juodiSection);
        $this->assertStringNotContainsString('melyni.jpg', $juodiSection);
    }

    public function test_duplicate_variant_images_are_deduplicated_in_export(): void
    {
        VarleCatalogFixtures::createMultiVariantProduct(
            productOverrides: ['handle' => 'dedupe-images-product'],
            variantDefinitions: [
                [
                    'sku' => 'SKU-A',
                    'barcode' => '4770000000501',
                    'price' => 20,
                    'option1' => 'S',
                    'option1_name' => 'Dydis',
                    'option1_value' => 'S',
                    'image_url' => 'https://cdn.example.com/shared-variant.jpg',
                ],
                [
                    'sku' => 'SKU-B',
                    'barcode' => '4770000000502',
                    'price' => 22,
                    'option1' => 'M',
                    'option1_name' => 'Dydis',
                    'option1_value' => 'M',
                    'image_url' => 'https://cdn.example.com/shared-variant.jpg',
                ],
            ],
        );

        $this->makeExporter()->export();
        $xml = Storage::disk('public')->get('feeds/varle.xml');

        $this->assertSame(1, substr_count($xml, '<image><![CDATA[https://cdn.example.com/shared-variant.jpg]]></image>'));
    }

    public function test_product_images_are_not_used_when_variant_images_exist(): void
    {
        VarleCatalogFixtures::createExportableVariant(
            productOverrides: ['handle' => 'variant-image-only'],
            variantOverrides: [
                'image_url' => 'https://cdn.example.com/variant-only.jpg',
            ],
        );

        Product::query()->where('handle', 'variant-image-only')->firstOrFail()
            ->images()
            ->update(['url' => 'https://cdn.example.com/product-only.jpg']);

        $this->makeExporter()->export();
        $xml = Storage::disk('public')->get('feeds/varle.xml');

        $this->assertStringContainsString('<image><![CDATA[https://cdn.example.com/variant-only.jpg]]></image>', $xml);
        $this->assertStringNotContainsString('product-only.jpg', $xml);
    }

    public function test_missing_variant_images_skip_product_when_fallback_disabled(): void
    {
        VarleCatalogFixtures::createExportableVariant(
            productOverrides: ['handle' => 'missing-variant-images'],
            variantOverrides: [
                'image_url' => null,
            ],
        );

        $result = $this->makeExporter()->export();

        $this->assertSame(0, $result->exportedVariants);
        $this->assertGreaterThan(0, $result->skippedVariants);

        $failedItem = SyncJobItem::query()->firstOrFail();
        $this->assertSame('No variant-specific images found', $failedItem->message);
        $this->assertStringNotContainsString('<product>', Storage::disk('public')->get('feeds/varle.xml'));
    }

    public function test_fallback_product_images_work_when_allow_fallback_product_images_is_true(): void
    {
        MarketplaceChannel::query()->updateOrCreate(
            ['type' => 'varle', 'name' => 'Varle.lt'],
            [
                'enabled' => true,
                'config' => [
                    'default_category' => 'Kita',
                    'export_zero_stock' => true,
                    'price_multiplier' => 1,
                    'feed_filename' => 'varle.xml',
                    'require_category_mapping' => false,
                    'allow_fallback_product_images' => true,
                ],
            ],
        );

        VarleCatalogFixtures::createExportableVariant(
            productOverrides: ['handle' => 'fallback-images-product'],
            variantOverrides: [
                'image_url' => null,
            ],
        );

        $this->makeExporter()->export();
        $xml = Storage::disk('public')->get('feeds/varle.xml');

        $this->assertStringContainsString('<image><![CDATA[https://cdn.example.com/image.jpg]]></image>', $xml);
    }

    private function makeExporter(): VarleXmlExporter
    {
        return $this->app->make(VarleXmlExporter::class);
    }

    private function extractProductXmlSection(string $xml, string $productId): string
    {
        $pattern = '/<product>(?:(?!<\/product>).)*<id>'.preg_quote($productId, '/').'<\/id>(?:(?!<\/product>).)*<\/product>/s';

        if (! preg_match($pattern, $xml, $matches)) {
            $this->fail('Product section not found for id: '.$productId);
        }

        return $matches[0];
    }
}
