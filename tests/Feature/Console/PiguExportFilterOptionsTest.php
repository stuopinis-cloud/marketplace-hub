<?php

namespace Tests\Feature\Console;

use App\Enums\ProductStatus;
use App\Models\CategoryMapping;
use App\Models\InventoryLevel;
use App\Models\MarketplaceChannel;
use App\Models\PiguDimensionRule;
use App\Models\PiguPropertyRule;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Source;
use App\Models\SourceCategory;
use App\Services\Marketplace\Pigu\PiguExportOptions;
use App\Services\Marketplace\Pigu\PiguProductFeedExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PiguExportFilterOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_easy_only_excludes_unmapped_categories(): void
    {
        $channel = $this->piguChannel();
        $easy = $this->seedProductWithCollection($channel, 'easy-handle', 'Easy Product', withFullEasyRules: true);
        $other = $this->seedProductWithCollection($channel, 'other-handle', 'Other Product', withFullEasyRules: false);

        $result = app(PiguProductFeedExporter::class)->export(new PiguExportOptions(easyOnly: true));

        $this->assertSame(1, $result['products_considered']);
        $this->assertSame(1, $result['exported_products']);
        $xml = Storage::disk('public')->get($result['feed_path']);
        $this->assertStringContainsString('Easy Product', $xml);
        $this->assertStringNotContainsString('Other Product', $xml);
        $this->assertFalse(collect($result['skipped_rows'])->contains(
            fn (array $row): bool => $row['product_id'] === $other->id,
        ));
        $this->assertSame($easy->id, Product::query()->where('title', 'Easy Product')->value('id'));
    }

    public function test_mapped_only_excludes_unmapped_categories(): void
    {
        $channel = $this->piguChannel();
        $mapped = $this->seedProductWithCollection($channel, 'mapped-handle', 'Mapped Product', withMapping: true);
        $unmapped = $this->seedProductWithCollection($channel, 'unmapped-handle', 'Unmapped Product', withMapping: false);

        $result = app(PiguProductFeedExporter::class)->export(new PiguExportOptions(mappedOnly: true));

        $this->assertSame(1, $result['products_considered']);
        $xml = Storage::disk('public')->get($result['feed_path']);
        $this->assertStringContainsString('Mapped Product', $xml);
        $this->assertStringNotContainsString('Unmapped Product', $xml);
        $this->assertFalse(collect($result['skipped_rows'])->contains(
            fn (array $row): bool => $row['product_id'] === $unmapped->id,
        ));
        $this->assertTrue(Product::query()->whereKey($mapped->id)->exists());
    }

    public function test_collection_exports_only_selected_collection(): void
    {
        $channel = $this->piguChannel();
        $this->seedProductWithCollection($channel, 'alpha-handle', 'Alpha Product', withMapping: true);
        $this->seedProductWithCollection($channel, 'beta-handle', 'Beta Product', withMapping: true);

        $result = app(PiguProductFeedExporter::class)->export(
            new PiguExportOptions(collection: 'alpha-handle'),
        );

        $this->assertSame(1, $result['products_considered']);
        $xml = Storage::disk('public')->get($result['feed_path']);
        $this->assertStringContainsString('Alpha Product', $xml);
        $this->assertStringNotContainsString('Beta Product', $xml);
    }

    public function test_failed_csv_includes_source_categories(): void
    {
        $channel = $this->piguChannel();
        $this->seedProductWithCollection(
            $channel,
            'fail-handle',
            'Fail Product',
            withMapping: false,
            barcode: '123',
        );

        $result = app(PiguProductFeedExporter::class)->export();
        $this->assertGreaterThan(0, $result['skipped_products']);

        $csv = Storage::disk('public')->get('exports/pigu_failed_latest.csv');
        $this->assertStringContainsString('source_categories', $csv);
        $this->assertStringContainsString('collection:fail-handle/', $csv);
        $this->assertNotEmpty($result['skipped_by_code']);
        $this->assertArrayHasKey('fail-handle', $result['skipped_by_source_category']);
    }

    public function test_easy_only_prefers_easy_mapping_over_higher_priority_non_easy(): void
    {
        $channel = $this->piguChannel();
        $source = Source::query()->firstOrCreate(
            ['type' => 'shopify'],
            ['name' => 'Shopify', 'enabled' => true, 'config' => []],
        );

        $easyCollection = SourceCategory::query()->create([
            'source_id' => $source->id,
            'type' => 'collection',
            'external_id' => 'ext-easy-dual',
            'name' => 'Easy Dual',
            'handle' => 'kelnes-vyrams',
        ]);

        $nonEasyCollection = SourceCategory::query()->create([
            'source_id' => $source->id,
            'type' => 'collection',
            'external_id' => 'ext-rankines',
            'name' => 'Rankines',
            'handle' => 'rankines',
        ]);

        CategoryMapping::query()->create([
            'marketplace_channel_id' => $channel->id,
            'source_type' => 'collection',
            'source_value' => 'rankines',
            'target_category_id' => '6830',
            'target_category_name' => 'Rankinės',
            'target_category_path' => '6830|Rankinės',
            'enabled' => true,
            'export_enabled' => true,
            'priority' => 10,
        ]);

        CategoryMapping::query()->create([
            'marketplace_channel_id' => $channel->id,
            'source_type' => 'collection',
            'source_value' => 'kelnes-vyrams',
            'target_category_id' => '9051',
            'target_category_name' => 'Vyriškos kelnės',
            'target_category_path' => '9051|Vyriškos kelnės',
            'enabled' => true,
            'export_enabled' => true,
            'priority' => 100,
        ]);

        PiguDimensionRule::query()->create([
            'match_type' => 'collection',
            'match_value' => 'kelnes-vyrams',
            'weight_kg' => 0.4,
            'length_m' => 0.3,
            'width_m' => 0.2,
            'height_m' => 0.05,
            'enabled' => true,
            'priority' => 100,
        ]);

        PiguPropertyRule::query()->create([
            'pigu_category_id' => 9051,
            'match_type' => 'collection',
            'match_value' => 'kelnes-vyrams',
            'property_name' => 'Tipas',
            'value_source' => 'fixed',
            'value_name' => 'Kelnės',
            'enabled' => true,
            'priority' => 100,
        ]);

        $product = Product::query()->create([
            'source_id' => $source->id,
            'external_id' => 'p-dual-'.uniqid(),
            'title' => 'Dual Collection Product',
            'description_html' => '<p>Dual collection description</p>',
            'vendor' => 'BrandCo',
            'product_type' => 'Type',
            'status' => ProductStatus::Active,
            'handle' => 'dual-collection-product',
            'imported_at' => now(),
        ]);
        $product->sourceCategories()->attach([$easyCollection->id, $nonEasyCollection->id]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'url' => 'https://cdn.example.com/dual.jpg',
            'position' => 0,
        ]);

        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'external_id' => 'v-dual-'.uniqid(),
            'sku' => 'SKU-DUAL',
            'barcode' => '5901234123999',
            'title' => 'Default Title',
            'price' => 10,
            'image_url' => 'https://cdn.example.com/dual.jpg',
            'option1_name' => 'Title',
            'option1_value' => 'Default Title',
        ]);

        InventoryLevel::query()->create([
            'variant_id' => $variant->id,
            'warehouse_name' => 'Shopify',
            'quantity' => 5,
        ]);

        $easyOnly = app(PiguProductFeedExporter::class)->export(new PiguExportOptions(easyOnly: true));
        $easyXml = Storage::disk('public')->get($easyOnly['feed_path']);

        $this->assertSame(1, $easyOnly['products_considered']);
        $this->assertSame(1, $easyOnly['exported_products']);
        $this->assertStringContainsString('<category-id>9051</category-id>', $easyXml);
        $this->assertStringNotContainsString('<category-id>6830</category-id>', $easyXml);
        $this->assertSame('kelnes-vyrams', $easyOnly['export_resolutions'][0]['chosen_source_handle'] ?? null);
        $this->assertSame('9051', $easyOnly['export_resolutions'][0]['chosen_category_id'] ?? null);
        $this->assertTrue($easyOnly['export_resolutions'][0]['easy_only_allowed'] ?? false);

        $full = app(PiguProductFeedExporter::class)->export();
        $fullXml = Storage::disk('public')->get($full['feed_path']);

        $this->assertSame(1, $full['products_considered']);
        $this->assertStringContainsString('<category-id>6830</category-id>', $fullXml);
        $this->assertFalse($full['export_resolutions'][0]['easy_only_allowed'] ?? true);
    }

    public function test_unfiltered_export_still_considers_all_products(): void
    {
        $channel = $this->piguChannel();
        $this->seedProductWithCollection($channel, 'a-handle', 'A Product', withMapping: true);
        $this->seedProductWithCollection($channel, 'b-handle', 'B Product', withMapping: false);

        $result = app(PiguProductFeedExporter::class)->export();

        $this->assertSame(2, $result['products_considered']);
    }

    private function piguChannel(): MarketplaceChannel
    {
        return MarketplaceChannel::query()->firstOrCreate(
            ['type' => 'pigu'],
            [
                'name' => 'Pigu / PHH',
                'enabled' => true,
                'config' => ['require_category_mapping' => true],
            ],
        );
    }

    private function seedProductWithCollection(
        MarketplaceChannel $channel,
        string $handle,
        string $title,
        bool $withMapping = true,
        bool $withFullEasyRules = false,
        string $barcode = '5901234123457',
    ): Product {
        $source = Source::query()->firstOrCreate(
            ['type' => 'shopify'],
            ['name' => 'Shopify', 'enabled' => true, 'config' => []],
        );

        $categoryId = (string) (10000 + abs(crc32($handle)) % 10000);

        $collection = SourceCategory::query()->create([
            'source_id' => $source->id,
            'type' => 'collection',
            'external_id' => 'ext-'.$handle,
            'name' => $title.' Collection',
            'handle' => $handle,
        ]);

        if ($withMapping || $withFullEasyRules) {
            CategoryMapping::query()->updateOrCreate(
                [
                    'marketplace_channel_id' => $channel->id,
                    'source_type' => 'collection',
                    'source_value' => $handle,
                ],
                [
                    'target_category_id' => $categoryId,
                    'target_category_name' => $title.' Cat',
                    'target_category_path' => $categoryId.'|'.$title.' Cat',
                    'enabled' => true,
                    'export_enabled' => true,
                    'priority' => 100,
                ],
            );
        }

        if ($withFullEasyRules) {
            PiguDimensionRule::query()->updateOrCreate(
                ['match_type' => 'collection', 'match_value' => $handle],
                [
                    'weight_kg' => 0.3,
                    'length_m' => 0.2,
                    'width_m' => 0.1,
                    'height_m' => 0.05,
                    'enabled' => true,
                    'priority' => 100,
                ],
            );

            PiguPropertyRule::query()->updateOrCreate(
                [
                    'pigu_category_id' => (int) $categoryId,
                    'match_type' => 'collection',
                    'match_value' => $handle,
                    'property_name' => 'Tipas',
                ],
                [
                    'value_source' => 'fixed',
                    'value_name' => 'Test',
                    'enabled' => true,
                    'priority' => 100,
                ],
            );
        }

        $product = Product::query()->create([
            'source_id' => $source->id,
            'external_id' => 'p-'.$handle.'-'.uniqid(),
            'title' => $title,
            'description_html' => '<p>Description for '.$title.'</p>',
            'vendor' => 'BrandCo',
            'product_type' => 'Type',
            'status' => ProductStatus::Active,
            'handle' => 'handle-'.$handle,
            'imported_at' => now(),
        ]);
        $product->sourceCategories()->attach($collection->id);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'url' => 'https://cdn.example.com/'.$handle.'.jpg',
            'position' => 0,
        ]);

        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'external_id' => 'v-'.$handle.'-'.uniqid(),
            'sku' => 'SKU-'.$handle,
            'barcode' => $barcode === '5901234123457' ? '590'.substr((string) abs(crc32($handle)), 0, 10) : $barcode,
            'title' => 'Default Title',
            'price' => 10,
            'image_url' => 'https://cdn.example.com/'.$handle.'.jpg',
            'option1_name' => 'Title',
            'option1_value' => 'Default Title',
        ]);

        // Ensure barcode is always 13 digits for valid exports.
        if (strlen((string) $variant->barcode) !== 13 || ! ctype_digit((string) $variant->barcode)) {
            if ($barcode === '123') {
                $variant->update(['barcode' => '123']);
            } else {
                $variant->update(['barcode' => str_pad(substr((string) abs(crc32($handle.$title)), 0, 12), 13, '0')]);
            }
        }

        InventoryLevel::query()->create([
            'variant_id' => $variant->id,
            'warehouse_name' => 'Shopify',
            'quantity' => 5,
        ]);

        return $product->fresh(['sourceCategories', 'variants', 'images']);
    }
}
