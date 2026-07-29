<?php

namespace Tests\Feature\Console;

use App\Enums\ProductStatus;
use App\Enums\FeedFileStatus;
use App\Models\CategoryMapping;
use App\Models\InventoryLevel;
use App\Models\MarketplaceChannel;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Source;
use App\Services\Marketplace\Pigu\PiguDescriptionSanitizer;
use App\Services\Marketplace\Pigu\PiguProductFeedExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PiguExportProductsXmlCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_creates_public_feed_and_route_returns_xml(): void
    {
        $this->seedExportableProduct();

        $exitCode = Artisan::call('pigu:export-products-xml');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('Public path:', $output);
        $this->assertStringContainsString('app/public/feeds/pigu-products.xml', $output);

        $absolute = storage_path('app/public/feeds/pigu-products.xml');
        $this->assertFileExists($absolute);
        $this->assertFileDoesNotExist(storage_path('app/private/feeds/pigu-products.xml'));
        $this->assertTrue(Storage::disk('public')->exists('feeds/pigu-products.xml'));

        $xml = (string) file_get_contents($absolute);
        $this->assertNotFalse(@simplexml_load_string($xml));
        $this->assertStringContainsString('<products xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">', $xml);
        $this->assertStringContainsString('<title><![CDATA[', $xml);
        $this->assertStringContainsString('<long-description><![CDATA[', $xml);
        $this->assertStringContainsString('<category-name><![CDATA[', $xml);

        $response = $this->get('/feeds/pigu-products.xml');
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $response->assertHeader('Cache-Control', 'max-age=300, public');

        $this->assertDatabaseHas('feed_files', [
            'filename' => 'pigu-products.xml',
            'path' => 'feeds/pigu-products.xml',
            'public_url' => 'https://hub.gudle.lt/feeds/pigu-products.xml',
            'status' => FeedFileStatus::Generated->value,
        ]);
    }

    public function test_product_without_category_mapping_is_skipped(): void
    {
        $this->seedExportableProduct(withMapping: false);

        $result = app(PiguProductFeedExporter::class)->export();

        $this->assertSame(0, $result['exported_products']);
        $this->assertSame(1, $result['skipped_products']);
        $this->assertTrue(collect($result['skipped_rows'])->contains(
            fn (array $row): bool => $row['code'] === 'missing_pigu_category_mapping',
        ));
    }

    public function test_invalid_barcode_is_skipped(): void
    {
        $product = $this->seedExportableProduct(barcode: '12345');

        $result = app(PiguProductFeedExporter::class)->export();

        $this->assertSame(0, $result['exported_products']);
        $this->assertTrue(collect($result['skipped_rows'])->contains(
            fn (array $row): bool => $row['code'] === 'invalid_barcode' && $row['product_id'] === $product->id,
        ));
    }

    public function test_duplicate_barcode_is_flagged(): void
    {
        $this->seedExportableProduct(barcode: '5901234123457', sku: 'SKU-A');
        $this->seedExportableProduct(barcode: '5901234123457', sku: 'SKU-B', externalSuffix: 'b');

        $result = app(PiguProductFeedExporter::class)->export();

        $this->assertSame(1, $result['exported_products']);
        $this->assertTrue(collect($result['skipped_rows'])->contains(
            fn (array $row): bool => $row['code'] === 'duplicate_barcode',
        ));
    }

    public function test_http_images_are_flagged_and_https_required(): void
    {
        $product = $this->seedExportableProduct(imageUrl: 'http://cdn.example.com/insecure.jpg');

        $result = app(PiguProductFeedExporter::class)->export();

        $this->assertSame(0, $result['exported_products']);
        $codes = collect($result['skipped_rows'])->pluck('code');
        $this->assertTrue($codes->contains('image_not_https') || $codes->contains('missing_image'));
        $this->assertSame($product->id, $result['skipped_rows'][0]['product_id']);
    }

    public function test_colour_split_creates_separate_products_with_one_colour_each(): void
    {
        $this->seedColorSizeProduct();

        $result = app(PiguProductFeedExporter::class)->export();
        $xml = Storage::disk('public')->get($result['feed_path']);

        $this->assertSame(2, $result['exported_products']);
        $this->assertSame(4, $result['exported_modifications']);
        $this->assertSame(2, substr_count($xml, '<product>'));
        $this->assertSame(2, substr_count($xml, '<colour>'));
        $this->assertStringContainsString('<colour-title><![CDATA[Juoda]]></colour-title>', $xml);
        $this->assertStringContainsString('<colour-title><![CDATA[Žalia]]></colour-title>', $xml);
        $this->assertStringContainsString('<modification-title><![CDATA[S]]></modification-title>', $xml);
        $this->assertStringContainsString('<modification-title><![CDATA[M]]></modification-title>', $xml);
    }

    public function test_simple_product_has_empty_modification_title(): void
    {
        $this->seedExportableProduct(simple: true);

        $result = app(PiguProductFeedExporter::class)->export();
        $xml = Storage::disk('public')->get($result['feed_path']);

        $this->assertSame(1, $result['exported_products']);
        $this->assertStringContainsString('<modification-title></modification-title>', $xml);
    }

    public function test_zero_quantity_is_not_exported(): void
    {
        $this->seedExportableProduct(quantity: 0);

        $result = app(PiguProductFeedExporter::class)->export();

        $this->assertSame(0, $result['exported_products']);
        $this->assertTrue(collect($result['skipped_rows'])->contains(
            fn (array $row): bool => $row['code'] === 'quantity_not_exportable',
        ));
    }

    public function test_dimensions_use_dot_decimal_and_defaults(): void
    {
        config([
            'marketplace.exports.pigu.default_weight_kg' => 0.5,
            'marketplace.exports.pigu.default_length_m' => 0.3,
            'marketplace.exports.pigu.default_height_m' => 0.1,
            'marketplace.exports.pigu.default_width_m' => 0.2,
        ]);

        $this->seedExportableProduct();

        $xml = Storage::disk('public')->get(app(PiguProductFeedExporter::class)->export()['feed_path']);

        $this->assertStringContainsString('<weight>0.5</weight>', $xml);
        $this->assertStringContainsString('<length>0.3</length>', $xml);
        $this->assertStringContainsString('<height>0.1</height>', $xml);
        $this->assertStringContainsString('<width>0.2</width>', $xml);
        $this->assertDoesNotMatchRegularExpression('/<(weight|length|height|width)>\d+,\d+/', $xml);
    }

    public function test_exporter_outputs_category_id_and_name_from_mapping(): void
    {
        $this->seedExportableProduct(
            categoryId: '4242',
            categoryName: 'Vyriškos kelnės',
        );

        $xml = Storage::disk('public')->get(app(PiguProductFeedExporter::class)->export()['feed_path']);

        $this->assertStringContainsString('<category-id>4242</category-id>', $xml);
        $this->assertStringContainsString('<category-name><![CDATA[Vyriškos kelnės]]></category-name>', $xml);
    }

    public function test_plain_path_without_id_is_not_treated_as_category_id(): void
    {
        $product = $this->seedExportableProduct(withMapping: false);

        $channel = MarketplaceChannel::query()->where('type', 'pigu')->firstOrFail();
        CategoryMapping::query()->create([
            'marketplace_channel_id' => $channel->id,
            'source_type' => 'product_type',
            'source_value' => 'Backpacks',
            'target_category_path' => 'Vyriškos kelnės',
            'target_category_id' => null,
            'target_category_name' => null,
            'priority' => 10,
            'enabled' => true,
            'export_enabled' => true,
        ]);

        $result = app(PiguProductFeedExporter::class)->export();

        $this->assertSame(0, $result['exported_products']);
        $this->assertTrue(collect($result['skipped_rows'])->contains(
            fn (array $row): bool => in_array($row['code'], ['missing_pigu_category_mapping', 'missing_category_id'], true)
                && $row['product_id'] === $product->id,
        ));
    }

    public function test_description_sanitizer_removes_unsupported_entities_and_tags(): void
    {
        $sanitizer = new PiguDescriptionSanitizer;
        $clean = $sanitizer->sanitize('<p>Hello&nbsp;world &sdot; <script>alert(1)</script><a href="x">x</a><img src="y"/></p>');

        $this->assertStringContainsString('Hello world', $clean);
        $this->assertStringNotContainsString('&nbsp;', $clean);
        $this->assertStringNotContainsString('script', $clean);
        $this->assertStringNotContainsString('<a', $clean);
        $this->assertStringNotContainsString('<img', $clean);
    }

    private function seedExportableProduct(
        bool $withMapping = true,
        string $barcode = '5901234123457',
        string $sku = 'SKU-PIGU-1',
        string $imageUrl = 'https://cdn.example.com/pigu.jpg',
        int $quantity = 5,
        bool $simple = false,
        string $externalSuffix = 'a',
        string $categoryId = '1268',
        string $categoryName = 'Shirts',
    ): Product {
        $source = Source::query()->firstOrCreate(
            ['type' => 'shopify'],
            ['name' => 'Shopify', 'enabled' => true, 'config' => []],
        );

        $channel = MarketplaceChannel::query()->firstOrCreate(
            ['type' => 'pigu', 'name' => 'Pigu'],
            ['enabled' => true, 'config' => ['require_category_mapping' => true]],
        );

        $product = Product::query()->create([
            'source_id' => $source->id,
            'external_id' => 'pigu-'.$externalSuffix.'-'.uniqid(),
            'title' => 'Pigu Product '.$externalSuffix,
            'description_html' => '<p>Long description with &nbsp; entity</p>',
            'vendor' => 'BrandCo',
            'product_type' => 'Backpacks',
            'status' => ProductStatus::Active,
            'handle' => 'pigu-product-'.$externalSuffix,
            'raw_payload' => $simple ? ['options' => [['name' => 'Title']]] : [
                'options' => [['name' => 'Title']],
            ],
            'imported_at' => now(),
        ]);

        if ($withMapping) {
            CategoryMapping::query()->create([
                'marketplace_channel_id' => $channel->id,
                'source_type' => 'product_type',
                'source_value' => 'Backpacks',
                'target_category_path' => $categoryId.'|'.$categoryName,
                'target_category_id' => $categoryId,
                'target_category_name' => $categoryName,
                'priority' => 10,
                'enabled' => true,
                'export_enabled' => true,
            ]);
        }

        ProductImage::query()->create([
            'product_id' => $product->id,
            'url' => $imageUrl,
            'position' => 0,
        ]);

        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'external_id' => 'var-'.$externalSuffix.'-'.uniqid(),
            'sku' => $sku,
            'barcode' => $barcode,
            'title' => $simple ? 'Default Title' : 'Default',
            'price' => 19.99,
            'image_url' => $imageUrl,
            'option1' => $simple ? 'Default Title' : null,
            'option1_name' => $simple ? 'Title' : null,
            'option1_value' => $simple ? 'Default Title' : null,
            'raw_payload' => $simple ? [
                'selectedOptions' => [['name' => 'Title', 'value' => 'Default Title']],
            ] : [],
        ]);

        InventoryLevel::query()->create([
            'variant_id' => $variant->id,
            'warehouse_name' => 'Shopify',
            'quantity' => $quantity,
        ]);

        return $product->fresh(['variants.inventoryLevels', 'images']);
    }

    private function seedColorSizeProduct(): Product
    {
        $source = Source::query()->firstOrCreate(
            ['type' => 'shopify'],
            ['name' => 'Shopify', 'enabled' => true, 'config' => []],
        );

        $channel = MarketplaceChannel::query()->firstOrCreate(
            ['type' => 'pigu', 'name' => 'Pigu'],
            ['enabled' => true, 'config' => ['require_category_mapping' => true]],
        );

        CategoryMapping::query()->firstOrCreate(
            [
                'marketplace_channel_id' => $channel->id,
                'source_type' => 'product_type',
                'source_value' => 'Apparel',
            ],
            [
                'target_category_path' => '1268|Shirts',
                'target_category_id' => '1268',
                'target_category_name' => 'Shirts',
                'priority' => 10,
                'enabled' => true,
                'export_enabled' => true,
            ],
        );

        $product = Product::query()->create([
            'source_id' => $source->id,
            'external_id' => 'pigu-color-'.uniqid(),
            'title' => 'Colour Split Shirt',
            'description_html' => '<p>Shirt description</p>',
            'vendor' => 'BrandCo',
            'product_type' => 'Apparel',
            'status' => ProductStatus::Active,
            'handle' => 'colour-split-shirt',
            'raw_payload' => [
                'options' => [
                    ['name' => 'Spalva'],
                    ['name' => 'Dydis'],
                ],
            ],
            'imported_at' => now(),
        ]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'url' => 'https://cdn.example.com/generic.jpg',
            'position' => 0,
        ]);

        $defs = [
            ['Juoda', 'S', '4770000000101', 'BLK-S'],
            ['Juoda', 'M', '4770000000102', 'BLK-M'],
            ['Žalia', 'S', '4770000000103', 'GRN-S'],
            ['Žalia', 'M', '4770000000104', 'GRN-M'],
        ];

        foreach ($defs as [$color, $size, $barcode, $sku]) {
            $variant = ProductVariant::query()->create([
                'product_id' => $product->id,
                'external_id' => 'var-'.uniqid(),
                'sku' => $sku,
                'barcode' => $barcode,
                'title' => "{$color} / {$size}",
                'price' => 25,
                'image_url' => 'https://cdn.example.com/'.strtolower($sku).'.jpg',
                'option1_name' => 'Spalva',
                'option1_value' => $color,
                'option1' => $color,
                'option2_name' => 'Dydis',
                'option2_value' => $size,
                'option2' => $size,
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
                'quantity' => 3,
            ]);
        }

        return $product->fresh(['variants.inventoryLevels', 'images']);
    }
}
