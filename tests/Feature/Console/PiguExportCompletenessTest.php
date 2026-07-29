<?php

namespace Tests\Feature\Console;

use App\Enums\FeedFileStatus;
use App\Enums\ProductStatus;
use App\Models\CategoryMapping;
use App\Models\InventoryLevel;
use App\Models\MarketplaceChannel;
use App\Models\PiguCategoryField;
use App\Models\PiguDimensionRule;
use App\Models\PiguPropertyRule;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Source;
use App\Models\SourceCategory;
use App\Services\Marketplace\Pigu\PiguDescriptionSanitizer;
use App\Services\Marketplace\Pigu\PiguExportOptions;
use App\Services\Marketplace\Pigu\PiguOfferFeedExporter;
use App\Services\Marketplace\Pigu\PiguProductFeedExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PiguExportCompletenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_webp_images_are_not_exported_and_product_with_only_webp_is_skipped(): void
    {
        $this->seedBaseProduct(imageUrl: 'https://cdn.example.com/img.webp');

        $result = app(PiguProductFeedExporter::class)->export();

        $this->assertSame(0, $result['exported_products']);
        $this->assertTrue(collect($result['skipped_rows'])->contains(
            fn (array $row): bool => in_array($row['code'], ['invalid_image_format_webp', 'missing_image'], true),
        ));
    }

    public function test_shopify_webp_is_transformed_to_jpg_when_possible(): void
    {
        $this->seedBaseProduct(imageUrl: 'https://cdn.shopify.com/s/files/1/product.webp');

        $xml = Storage::disk('public')->get(app(PiguProductFeedExporter::class)->export()['feed_path']);

        $this->assertStringContainsString('https://cdn.shopify.com/s/files/1/product.jpg', $xml);
        $this->assertStringNotContainsString('.webp', $xml);
    }

    public function test_jpg_and_png_images_pass(): void
    {
        $this->seedBaseProduct(imageUrl: 'https://cdn.example.com/ok.png');

        $result = app(PiguProductFeedExporter::class)->export();
        $xml = Storage::disk('public')->get($result['feed_path']);

        $this->assertSame(1, $result['exported_products']);
        $this->assertStringContainsString('https://cdn.example.com/ok.png', $xml);
        $this->assertStringNotContainsString('<price>', $xml);
    }

    public function test_manufacturer_name_exports_product_vendor(): void
    {
        $this->seedBaseProduct(vendor: 'Mechanix');

        $xml = Storage::disk('public')->get(app(PiguProductFeedExporter::class)->export()['feed_path']);

        $this->assertStringContainsString('<manufacturer-name><![CDATA[Mechanix]]></manufacturer-name>', $xml);
    }

    public function test_lens_colour_variants_split_into_separate_products(): void
    {
        $this->seedLensVariantProduct();

        $result = app(PiguProductFeedExporter::class)->export();
        $xml = Storage::disk('public')->get($result['feed_path']);

        $this->assertSame(3, $result['exported_products']);
        $this->assertStringContainsString('<colour-title><![CDATA[Skaidrūs]]></colour-title>', $xml);
        $this->assertStringContainsString('<colour-title><![CDATA[Geltoni]]></colour-title>', $xml);
        $this->assertStringContainsString('<colour-title><![CDATA[Tamsinti]]></colour-title>', $xml);
        $this->assertStringContainsString('Taktiniai akiniai - Skaidrūs', $xml);
        $this->assertStringNotContainsString('<modification-title><![CDATA[Skaidrūs]]></modification-title>', $xml);
    }

    public function test_size_variants_remain_modifications_inside_colour_product(): void
    {
        $this->seedColorSizeProduct();

        $result = app(PiguProductFeedExporter::class)->export();
        $xml = Storage::disk('public')->get($result['feed_path']);

        $this->assertSame(2, $result['exported_products']);
        $this->assertStringContainsString('<modification-title><![CDATA[S]]></modification-title>', $xml);
        $this->assertStringContainsString('<modification-title><![CDATA[M]]></modification-title>', $xml);
    }

    public function test_required_category_properties_are_enforced_under_easy_only(): void
    {
        $channel = $this->piguChannel();
        $this->seedEasyProductWithMissingMandatory($channel);

        PiguCategoryField::query()->create([
            'lowest_category_id' => 4757,
            'top_category_title' => 'Apsauga',
            'lowest_category_title' => 'Akiniai',
            'field_id' => 1,
            'field_title' => 'Medžiaga',
            'field_title_en' => 'Material',
            'mandatory' => true,
            'field_value_type' => 'list',
        ]);

        $result = app(PiguProductFeedExporter::class)->export(new PiguExportOptions(easyOnly: true));

        $this->assertSame(0, $result['exported_products']);
        $this->assertTrue(collect($result['skipped_rows'])->contains(
            fn (array $row): bool => $row['code'] === 'missing_required_property',
        ));
    }

    public function test_property_id_uses_human_property_name(): void
    {
        $this->seedBaseProduct();

        PiguPropertyRule::query()->create([
            'pigu_category_id' => 1268,
            'match_type' => 'product_type',
            'match_value' => 'Backpacks',
            'property_name' => 'Prekės ženklas',
            'property_id' => 91447,
            'value_source' => PiguPropertyRule::VALUE_SOURCE_VENDOR,
            'enabled' => true,
            'priority' => 10,
        ]);

        $xml = Storage::disk('public')->get(app(PiguProductFeedExporter::class)->export()['feed_path']);

        $this->assertStringContainsString('<id><![CDATA[Prekės ženklas]]></id>', $xml);
        $this->assertStringNotContainsString('<id><![CDATA[91447]]></id>', $xml);
    }

    public function test_product_xml_does_not_include_price_and_export_warns_about_offer_feed(): void
    {
        $this->seedBaseProduct();

        $result = app(PiguProductFeedExporter::class)->export();
        $xml = Storage::disk('public')->get($result['feed_path']);

        $this->assertStringNotContainsString('<price>', $xml);
        $this->assertStringNotContainsString('price-before-discount', $xml);
        $this->assertTrue(collect($result['warnings'])->contains(
            fn (string $warning): bool => str_contains($warning, 'does not include prices'),
        ));
    }

    public function test_offer_feed_exports_sku_ean_price_and_stock(): void
    {
        $this->seedBaseProduct(price: 19.99);

        $result = app(PiguOfferFeedExporter::class)->export();
        $xml = Storage::disk('public')->get($result['feed_path']);

        $this->assertGreaterThan(0, $result['offers_exported']);
        $this->assertStringContainsString('<sku><![CDATA[SKU-BASE]]></sku>', $xml);
        $this->assertStringContainsString('<ean><![CDATA[5901234123457]]></ean>', $xml);
        $this->assertStringContainsString('<price-before-discount>19.99</price-before-discount>', $xml);
        $this->assertStringContainsString('<price-after-discount>19.99</price-after-discount>', $xml);
        $this->assertStringContainsString('<stock>5</stock>', $xml);
        $this->assertStringContainsString('<collectionhours>24</collectionhours>', $xml);

        $this->get('/feeds/pigu-offers.xml')->assertOk();

        $this->assertDatabaseHas('feed_files', [
            'filename' => 'pigu-offers.xml',
            'path' => 'feeds/pigu-offers.xml',
            'public_url' => 'https://hub.gudle.lt/feeds/pigu-offers.xml',
            'status' => FeedFileStatus::Generated->value,
        ]);
    }

    public function test_validation_command_reports_offer_feed_warning_separately(): void
    {
        $this->seedBaseProduct();
        app(PiguProductFeedExporter::class)->export();

        $exitCode = Artisan::call('pigu:validate-products-xml');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('does not include prices', $output);
        $this->assertStringContainsString('validation passed', strtolower($output));
    }

    public function test_description_converts_bullet_lines_to_list(): void
    {
        $sanitizer = new PiguDescriptionSanitizer;
        $clean = $sanitizer->sanitize("Intro<br>• One<br>• Two<br>• Three");

        $this->assertStringContainsString('<ul>', $clean);
        $this->assertStringContainsString('<li>One</li>', $clean);
        $this->assertStringContainsString('<li>Two</li>', $clean);
    }

    private function piguChannel(): MarketplaceChannel
    {
        return MarketplaceChannel::query()->firstOrCreate(
            ['type' => 'pigu'],
            ['name' => 'Pigu / PHH', 'enabled' => true, 'config' => ['require_category_mapping' => true]],
        );
    }

    private function seedBaseProduct(
        string $imageUrl = 'https://cdn.example.com/ok.jpg',
        string $vendor = 'BrandCo',
        float $price = 10.0,
    ): Product {
        $source = Source::query()->firstOrCreate(
            ['type' => 'shopify'],
            ['name' => 'Shopify', 'enabled' => true, 'config' => []],
        );
        $channel = $this->piguChannel();

        CategoryMapping::query()->create([
            'marketplace_channel_id' => $channel->id,
            'source_type' => 'product_type',
            'source_value' => 'Backpacks',
            'target_category_id' => '1268',
            'target_category_name' => 'Shirts',
            'target_category_path' => '1268|Shirts',
            'priority' => 10,
            'enabled' => true,
            'export_enabled' => true,
        ]);

        $product = Product::query()->create([
            'source_id' => $source->id,
            'external_id' => 'p-base-'.uniqid(),
            'title' => 'Base Product',
            'description_html' => '<p>Description</p>',
            'vendor' => $vendor,
            'product_type' => 'Backpacks',
            'status' => ProductStatus::Active,
            'handle' => 'base-product',
            'imported_at' => now(),
        ]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'url' => $imageUrl,
            'position' => 0,
        ]);

        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'external_id' => 'v-base-'.uniqid(),
            'sku' => 'SKU-BASE',
            'barcode' => '5901234123457',
            'title' => 'Default Title',
            'price' => $price,
            'image_url' => $imageUrl,
            'option1_name' => 'Title',
            'option1_value' => 'Default Title',
        ]);

        InventoryLevel::query()->create([
            'variant_id' => $variant->id,
            'warehouse_name' => 'Shopify',
            'quantity' => 5,
        ]);

        return $product->fresh(['variants', 'images']);
    }

    private function seedLensVariantProduct(): void
    {
        $source = Source::query()->firstOrCreate(
            ['type' => 'shopify'],
            ['name' => 'Shopify', 'enabled' => true, 'config' => []],
        );
        $channel = $this->piguChannel();

        CategoryMapping::query()->create([
            'marketplace_channel_id' => $channel->id,
            'source_type' => 'product_type',
            'source_value' => 'Glasses',
            'target_category_id' => '9001',
            'target_category_name' => 'Akiniai',
            'target_category_path' => '9001|Akiniai',
            'priority' => 10,
            'enabled' => true,
            'export_enabled' => true,
        ]);

        $product = Product::query()->create([
            'source_id' => $source->id,
            'external_id' => 'p-lens-'.uniqid(),
            'title' => 'Taktiniai akiniai',
            'description_html' => '<p>Lens product</p>',
            'vendor' => 'Mechanix',
            'product_type' => 'Glasses',
            'status' => ProductStatus::Active,
            'handle' => 'tactical-glasses',
            'raw_payload' => [
                'options' => [['name' => 'Lęšiai', 'values' => ['Skaidrūs', 'Geltoni', 'Tamsinti']]],
            ],
            'imported_at' => now(),
        ]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'url' => 'https://cdn.example.com/glasses.jpg',
            'position' => 0,
        ]);

        $defs = [
            ['Skaidrūs', '5901234123001', 'SKU-CLEAR'],
            ['Geltoni', '5901234123002', 'SKU-YELLOW'],
            ['Tamsinti', '5901234123003', 'SKU-DARK'],
        ];

        foreach ($defs as [$lens, $barcode, $sku]) {
            $variant = ProductVariant::query()->create([
                'product_id' => $product->id,
                'external_id' => 'v-'.$sku,
                'sku' => $sku,
                'barcode' => $barcode,
                'title' => $lens,
                'price' => 25,
                'image_url' => 'https://cdn.example.com/glasses-'.strtolower($sku).'.jpg',
                'option1_name' => 'Lęšiai',
                'option1_value' => $lens,
                'raw_payload' => [
                    'selectedOptions' => [['name' => 'Lęšiai', 'value' => $lens]],
                ],
            ]);

            InventoryLevel::query()->create([
                'variant_id' => $variant->id,
                'warehouse_name' => 'Shopify',
                'quantity' => 3,
            ]);
        }
    }

    private function seedColorSizeProduct(): void
    {
        $source = Source::query()->firstOrCreate(
            ['type' => 'shopify'],
            ['name' => 'Shopify', 'enabled' => true, 'config' => []],
        );
        $channel = $this->piguChannel();

        CategoryMapping::query()->create([
            'marketplace_channel_id' => $channel->id,
            'source_type' => 'product_type',
            'source_value' => 'Shirts',
            'target_category_id' => '1268',
            'target_category_name' => 'Shirts',
            'target_category_path' => '1268|Shirts',
            'priority' => 10,
            'enabled' => true,
            'export_enabled' => true,
        ]);

        $product = Product::query()->create([
            'source_id' => $source->id,
            'external_id' => 'p-cs-'.uniqid(),
            'title' => 'Colour Size Shirt',
            'description_html' => '<p>Shirt</p>',
            'vendor' => 'BrandCo',
            'product_type' => 'Shirts',
            'status' => ProductStatus::Active,
            'handle' => 'colour-size-shirt',
            'raw_payload' => [
                'options' => [
                    ['name' => 'Spalva', 'values' => ['Juoda', 'Žalia']],
                    ['name' => 'Dydis', 'values' => ['S', 'M']],
                ],
            ],
            'imported_at' => now(),
        ]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'url' => 'https://cdn.example.com/shirt.jpg',
            'position' => 0,
        ]);

        $i = 0;
        foreach (['Juoda', 'Žalia'] as $color) {
            foreach (['S', 'M'] as $size) {
                $i++;
                $variant = ProductVariant::query()->create([
                    'product_id' => $product->id,
                    'external_id' => 'v-cs-'.$i,
                    'sku' => 'SKU-CS-'.$i,
                    'barcode' => '5901234123'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                    'title' => "{$color} / {$size}",
                    'price' => 15,
                    'image_url' => 'https://cdn.example.com/shirt-'.$i.'.jpg',
                    'option1_name' => 'Spalva',
                    'option1_value' => $color,
                    'option2_name' => 'Dydis',
                    'option2_value' => $size,
                    'raw_payload' => [
                        'selectedOptions' => [
                            ['name' => 'Spalva', 'value' => $color],
                            ['name' => 'Dydis', 'value' => $size],
                        ],
                    ],
                ]);

                InventoryLevel::query()->create([
                    'variant_id' => $variant->id,
                    'warehouse_name' => 'Shopify',
                    'quantity' => 4,
                ]);
            }
        }
    }

    private function seedEasyProductWithMissingMandatory(MarketplaceChannel $channel): void
    {
        $source = Source::query()->firstOrCreate(
            ['type' => 'shopify'],
            ['name' => 'Shopify', 'enabled' => true, 'config' => []],
        );

        $collection = SourceCategory::query()->create([
            'source_id' => $source->id,
            'type' => 'collection',
            'external_id' => 'ext-easy-miss',
            'name' => 'Easy Miss',
            'handle' => 'easy-miss',
        ]);

        CategoryMapping::query()->create([
            'marketplace_channel_id' => $channel->id,
            'source_type' => 'collection',
            'source_value' => 'easy-miss',
            'target_category_id' => '4757',
            'target_category_name' => 'Akiniai',
            'target_category_path' => '4757|Akiniai',
            'enabled' => true,
            'export_enabled' => true,
            'priority' => 100,
        ]);

        PiguDimensionRule::query()->create([
            'match_type' => 'collection',
            'match_value' => 'easy-miss',
            'weight_kg' => 0.2,
            'length_m' => 0.2,
            'width_m' => 0.1,
            'height_m' => 0.05,
            'enabled' => true,
            'priority' => 100,
        ]);

        PiguPropertyRule::query()->create([
            'pigu_category_id' => 4757,
            'match_type' => 'collection',
            'match_value' => 'easy-miss',
            'property_name' => 'Tipas',
            'value_source' => PiguPropertyRule::VALUE_SOURCE_FIXED,
            'value_name' => 'Akiniai',
            'enabled' => true,
            'priority' => 100,
        ]);

        $product = Product::query()->create([
            'source_id' => $source->id,
            'external_id' => 'p-easy-miss-'.uniqid(),
            'title' => 'Easy Missing Property',
            'description_html' => '<p>Desc</p>',
            'vendor' => 'BrandCo',
            'product_type' => 'Glasses',
            'status' => ProductStatus::Active,
            'handle' => 'easy-miss-product',
            'imported_at' => now(),
        ]);
        $product->sourceCategories()->attach($collection->id);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'url' => 'https://cdn.example.com/easy.jpg',
            'position' => 0,
        ]);

        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'external_id' => 'v-easy-miss',
            'sku' => 'SKU-EASY-MISS',
            'barcode' => '5901234123777',
            'title' => 'Default Title',
            'price' => 12,
            'image_url' => 'https://cdn.example.com/easy.jpg',
            'option1_name' => 'Title',
            'option1_value' => 'Default Title',
        ]);

        InventoryLevel::query()->create([
            'variant_id' => $variant->id,
            'warehouse_name' => 'Shopify',
            'quantity' => 2,
        ]);
    }
}
