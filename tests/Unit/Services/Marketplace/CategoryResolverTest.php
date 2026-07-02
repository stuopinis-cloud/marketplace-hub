<?php

namespace Tests\Unit\Services\Marketplace;

use App\Models\CategoryMapping;
use App\Models\MarketplaceChannel;
use App\Models\Product;
use App\Models\Source;
use App\Models\SourceCategory;
use App\Services\Marketplace\CategoryResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\VarleCatalogFixtures;
use Tests\TestCase;

class CategoryResolverTest extends TestCase
{
    use RefreshDatabase;

    private CategoryResolver $resolver;

    private MarketplaceChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new CategoryResolver;
        $this->channel = MarketplaceChannel::query()->create([
            'name' => 'Varle.lt',
            'type' => 'varle',
            'enabled' => true,
            'config' => [
                'default_category' => 'Kita',
                'require_category_mapping' => false,
            ],
        ]);
    }

    public function test_collection_mapping_matches_by_name(): void
    {
        $product = $this->createProductWithSourceCategories([
            'category' => 'Manual Override',
            'product_type' => 'Pirštinės',
        ], [
            ['type' => 'collection', 'name' => 'Pirštinės', 'handle' => 'pirstines'],
        ]);

        CategoryMapping::query()->create([
            'marketplace_channel_id' => $this->channel->id,
            'source_type' => 'collection',
            'source_value' => 'Pirštinės',
            'target_category_path' => 'Apranga, avalynė, aksesuarai → Pirštinės → Vyriškos pirštinės',
            'priority' => 100,
            'enabled' => true,
        ]);

        $explanation = $this->resolver->explain($product, $this->channel);

        $this->assertSame('mapping', $explanation['source']);
        $this->assertFalse($explanation['fallback_used']);
        $this->assertSame(
            'Apranga, avalynė, aksesuarai → Pirštinės → Vyriškos pirštinės',
            $explanation['resolved_category'],
        );
    }

    public function test_collection_mapping_matches_by_handle(): void
    {
        $product = $this->createProductWithSourceCategories([
            'product_type' => 'Pirštinės',
        ], [
            ['type' => 'collection', 'name' => 'Pirštinės', 'handle' => 'pirstines'],
        ]);

        CategoryMapping::query()->create([
            'marketplace_channel_id' => $this->channel->id,
            'source_type' => 'collection',
            'source_value' => 'pirstines',
            'target_category_path' => 'Mapped By Handle',
            'priority' => 100,
            'enabled' => true,
        ]);

        $this->assertSame('Mapped By Handle', $this->resolver->resolve($product, $this->channel));
    }

    public function test_mapping_has_priority_over_product_category(): void
    {
        $product = $this->createProductWithSourceCategories([
            'category' => 'Manual Override',
            'product_type' => 'Pirštinės',
        ], [
            ['type' => 'collection', 'name' => 'Pirštinės', 'handle' => 'pirstines'],
        ]);

        CategoryMapping::query()->create([
            'marketplace_channel_id' => $this->channel->id,
            'source_type' => 'collection',
            'source_value' => 'Pirštinės',
            'target_category_path' => 'Mapped Category Path',
            'priority' => 100,
            'enabled' => true,
        ]);

        $explanation = $this->resolver->explain($product, $this->channel);

        $this->assertSame('Mapped Category Path', $explanation['resolved_category']);
        $this->assertSame('mapping', $explanation['source']);
        $this->assertNotSame('Manual Override', $explanation['resolved_category']);
    }

    public function test_resolver_uses_product_type_mapping(): void
    {
        $product = $this->createProductWithSourceCategories([
            'category' => null,
            'product_type' => 'Šarvinės liemenės',
        ]);

        CategoryMapping::query()->create([
            'marketplace_channel_id' => $this->channel->id,
            'source_type' => 'product_type',
            'source_value' => 'Šarvinės liemenės',
            'target_category_path' => 'Taktinė ekipuotė -> Šarvinės liemenės',
            'priority' => 100,
            'enabled' => true,
        ]);

        $this->assertSame(
            'Taktinė ekipuotė -> Šarvinės liemenės',
            $this->resolver->resolve($product, $this->channel),
        );
    }

    public function test_resolver_respects_priority(): void
    {
        $product = $this->createProductWithSourceCategories([
            'product_type' => 'Šarvinės liemenės',
        ], [
            ['type' => 'product_type', 'name' => 'Šarvinės liemenės'],
            ['type' => 'tag', 'name' => 'tactical'],
        ]);

        CategoryMapping::query()->create([
            'marketplace_channel_id' => $this->channel->id,
            'source_type' => 'tag',
            'source_value' => 'tactical',
            'target_category_path' => 'Low Priority Category',
            'priority' => 200,
            'enabled' => true,
        ]);

        CategoryMapping::query()->create([
            'marketplace_channel_id' => $this->channel->id,
            'source_type' => 'product_type',
            'source_value' => 'Šarvinės liemenės',
            'target_category_path' => 'High Priority Category',
            'priority' => 50,
            'enabled' => true,
        ]);

        $this->assertSame('High Priority Category', $this->resolver->resolve($product, $this->channel));
    }

    public function test_resolver_falls_back_to_product_category_when_no_mapping_and_require_mapping_disabled(): void
    {
        $product = $this->createProductWithSourceCategories([
            'category' => 'Fallback Category',
            'product_type' => 'Ignored Type',
        ]);

        $explanation = $this->resolver->explain($product, $this->channel);

        $this->assertSame('Fallback Category', $explanation['resolved_category']);
        $this->assertSame('product.category', $explanation['source']);
        $this->assertTrue($explanation['fallback_used']);
    }

    public function test_resolver_falls_back_to_default_category(): void
    {
        $product = $this->createProductWithSourceCategories([
            'category' => null,
            'product_type' => null,
        ]);

        $explanation = $this->resolver->explain($product, $this->channel);

        $this->assertSame('Kita', $explanation['resolved_category']);
        $this->assertSame('default_category', $explanation['source']);
        $this->assertTrue($explanation['fallback_used']);
    }

    public function test_resolver_returns_null_when_require_category_mapping_is_enabled_and_no_mapping_exists(): void
    {
        $this->channel->update([
            'config' => [
                'require_category_mapping' => true,
            ],
        ]);

        $product = $this->createProductWithSourceCategories([
            'category' => 'Should Not Be Used',
            'product_type' => 'Also Ignored',
        ]);

        $explanation = $this->resolver->explain($product, $this->channel);

        $this->assertNull($explanation['resolved_category']);
        $this->assertNull($explanation['source']);
        $this->assertFalse($explanation['fallback_used']);
    }

    public function test_resolver_counts_matching_products_for_mapping(): void
    {
        $product = $this->createProductWithSourceCategories([
            'product_type' => 'Šarvinės liemenės',
        ], [
            ['type' => 'tag', 'name' => 'tactical'],
        ]);

        $mapping = CategoryMapping::query()->create([
            'marketplace_channel_id' => $this->channel->id,
            'source_type' => 'tag',
            'source_value' => 'tactical',
            'target_category_path' => 'Tag Category',
            'priority' => 100,
            'enabled' => true,
        ]);

        $this->assertTrue($this->resolver->mappingMatchesProduct($mapping, $product));
        $this->assertSame(1, $this->resolver->countMatchingProducts($mapping));
    }

    /**
     * @param  array<string, mixed>  $productOverrides
     * @param  array<int, array<string, mixed>>  $sourceCategories
     */
    private function createProductWithSourceCategories(
        array $productOverrides = [],
        array $sourceCategories = [],
    ): Product {
        $variant = VarleCatalogFixtures::createExportableVariant($productOverrides);
        $product = $variant->product;

        if ($sourceCategories === []) {
            return $product->fresh(['sourceCategories']);
        }

        $source = Source::query()->firstOrFail();
        $categoryIds = [];

        foreach ($sourceCategories as $definition) {
            $category = SourceCategory::query()->create([
                'source_id' => $source->id,
                'type' => $definition['type'],
                'name' => $definition['name'],
                'handle' => $definition['handle'] ?? null,
                'external_id' => $definition['external_id'] ?? null,
            ]);

            $categoryIds[] = $category->id;
        }

        $product->sourceCategories()->sync($categoryIds);

        return $product->fresh(['sourceCategories']);
    }
}
