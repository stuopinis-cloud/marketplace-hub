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
use App\Models\SourceCategory;
use App\Services\Marketplace\Pigu\PiguProductFeedExporter;
use App\Services\Marketplace\Pigu\PiguReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PiguCategoryMappingResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_collection_handle_kelnes_vyrams_resolves_pigu_mapping(): void
    {
        [$product, $channel] = $this->seedKelnesVyramsCase();

        $resolved = app(PiguReadinessService::class)->resolvePiguCategory($product, $channel);

        $this->assertSame('17977', $resolved['id']);
        $this->assertSame('Vyriškos kelnės', $resolved['name']);
        $this->assertTrue($resolved['debug']['mapping_matched']);
        $this->assertSame('kelnes-vyrams', $resolved['debug']['matched_source_value']);
    }

    public function test_exporter_outputs_category_id_and_name_for_collection_handle_mapping(): void
    {
        $this->seedKelnesVyramsCase();

        $result = app(PiguProductFeedExporter::class)->export();
        $xml = Storage::disk('public')->get($result['feed_path']);

        $this->assertSame(1, $result['exported_products']);
        $this->assertStringContainsString('<category-id>17977</category-id>', $xml);
        $this->assertStringContainsString('<category-name><![CDATA[Vyriškos kelnės]]></category-name>', $xml);
    }

    public function test_exporter_uses_existing_pigu_channel_even_when_name_differs(): void
    {
        // Mimic production: channel named "Pigu / PHH", not "Pigu".
        $channel = MarketplaceChannel::query()->create([
            'type' => 'pigu',
            'name' => 'Pigu / PHH',
            'enabled' => true,
            'config' => ['require_category_mapping' => true],
        ]);

        $this->seedKelnesVyramsCase(channel: $channel);

        $resolvedChannel = app(PiguProductFeedExporter::class)->resolveChannel();
        $this->assertSame($channel->id, $resolvedChannel->id);
        $this->assertSame(1, MarketplaceChannel::query()->where('type', 'pigu')->count());

        $result = app(PiguProductFeedExporter::class)->export();
        $this->assertSame(1, $result['exported_products']);
        $this->assertSame($channel->id, $result['channel_id']);
    }

    public function test_missing_mapping_still_skips_product(): void
    {
        [$product] = $this->seedKelnesVyramsCase(withMapping: false);

        $result = app(PiguProductFeedExporter::class)->export();

        $this->assertSame(0, $result['exported_products']);
        $this->assertTrue(collect($result['skipped_rows'])->contains(
            fn (array $row): bool => $row['code'] === 'missing_pigu_category_mapping'
                && $row['product_id'] === $product->id,
        ));
    }

    public function test_disabled_or_export_disabled_mapping_is_ignored(): void
    {
        [$product, $channel] = $this->seedKelnesVyramsCase(withMapping: false);

        CategoryMapping::query()->create([
            'marketplace_channel_id' => $channel->id,
            'source_type' => 'collection',
            'source_value' => 'kelnes-vyrams',
            'target_category_path' => '17977|Vyriškos kelnės',
            'target_category_id' => '17977',
            'target_category_name' => 'Vyriškos kelnės',
            'priority' => 10,
            'enabled' => false,
            'export_enabled' => true,
        ]);

        CategoryMapping::query()->create([
            'marketplace_channel_id' => $channel->id,
            'source_type' => 'collection',
            'source_value' => 'kelnes-vyrams',
            'target_category_path' => '17977|Vyriškos kelnės',
            'target_category_id' => '17977',
            'target_category_name' => 'Vyriškos kelnės',
            'priority' => 20,
            'enabled' => true,
            'export_enabled' => false,
        ]);

        $resolved = app(PiguReadinessService::class)->resolvePiguCategory($product->fresh('sourceCategories'), $channel);
        $this->assertNull($resolved['id']);
        $this->assertNull($resolved['name']);

        $result = app(PiguProductFeedExporter::class)->export();
        $this->assertSame(0, $result['exported_products']);
    }

    /**
     * @return array{0: Product, 1: MarketplaceChannel}
     */
    private function seedKelnesVyramsCase(
        bool $withMapping = true,
        ?MarketplaceChannel $channel = null,
    ): array {
        $source = Source::query()->firstOrCreate(
            ['type' => 'shopify'],
            ['name' => 'Shopify', 'enabled' => true, 'config' => []],
        );

        $channel ??= MarketplaceChannel::query()->firstOrCreate(
            ['type' => 'pigu'],
            [
                'name' => 'Pigu / PHH',
                'enabled' => true,
                'config' => ['require_category_mapping' => true],
            ],
        );

        $sourceCategory = SourceCategory::query()->create([
            'source_id' => $source->id,
            'type' => 'collection',
            'external_id' => 'gid://shopify/Collection/118',
            'name' => 'Kelnės vyrams',
            'handle' => 'kelnes-vyrams',
        ]);

        $product = Product::query()->create([
            'source_id' => $source->id,
            'external_id' => 'pigu-pants-'.uniqid(),
            'title' => 'Tactical Pants',
            'description_html' => '<p>Long pants description</p>',
            'vendor' => 'BrandCo',
            'product_type' => 'Pants',
            'status' => ProductStatus::Active,
            'handle' => 'tactical-pants',
            'imported_at' => now(),
        ]);

        $product->sourceCategories()->attach($sourceCategory->id);

        if ($withMapping) {
            CategoryMapping::query()->create([
                'marketplace_channel_id' => $channel->id,
                'source_type' => 'collection',
                'source_value' => 'kelnes-vyrams',
                'target_category_path' => '17977|Vyriškos kelnės',
                'target_category_id' => '17977',
                'target_category_name' => 'Vyriškos kelnės',
                'priority' => 10,
                'enabled' => true,
                'export_enabled' => true,
            ]);
        }

        ProductImage::query()->create([
            'product_id' => $product->id,
            'url' => 'https://cdn.example.com/pants.jpg',
            'position' => 0,
        ]);

        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'external_id' => 'var-pants-'.uniqid(),
            'sku' => 'PANTS-1',
            'barcode' => '5901234123999',
            'title' => 'Default Title',
            'price' => 49.99,
            'image_url' => 'https://cdn.example.com/pants.jpg',
            'option1_name' => 'Title',
            'option1_value' => 'Default Title',
            'raw_payload' => [
                'selectedOptions' => [['name' => 'Title', 'value' => 'Default Title']],
            ],
        ]);

        InventoryLevel::query()->create([
            'variant_id' => $variant->id,
            'warehouse_name' => 'Shopify',
            'quantity' => 4,
        ]);

        return [$product->fresh(['sourceCategories', 'variants.inventoryLevels', 'images']), $channel];
    }
}
