<?php

namespace Tests\Unit\Models;

use App\Models\Source;
use App\Models\SourceCategory;
use App\Services\Marketplace\CategoryResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SourceCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_mapping_source_value_prefers_collection_handle(): void
    {
        $category = SourceCategory::query()->create([
            'source_id' => Source::query()->create([
                'type' => 'shopify',
                'name' => 'Shopify',
                'enabled' => true,
                'config' => [],
            ])->id,
            'type' => 'collection',
            'name' => 'Pirštinės',
            'handle' => 'pirstines',
        ]);

        $this->assertSame('pirstines', $category->mappingSourceValue());
        $this->assertSame('Pirštinės (handle: pirstines)', $category->selectLabel());
    }

    public function test_mapping_source_value_uses_name_for_product_type(): void
    {
        $category = SourceCategory::query()->create([
            'source_id' => Source::query()->create([
                'type' => 'shopify',
                'name' => 'Shopify',
                'enabled' => true,
                'config' => [],
            ])->id,
            'type' => 'product_type',
            'name' => 'Šarvinės liemenės',
        ]);

        $this->assertSame('Šarvinės liemenės', $category->mappingSourceValue());
        $this->assertSame('Šarvinės liemenės', $category->selectLabel());
    }

    public function test_find_for_mapping_matches_collection_by_handle_or_name(): void
    {
        $sourceId = Source::query()->create([
            'type' => 'shopify',
            'name' => 'Shopify',
            'enabled' => true,
            'config' => [],
        ])->id;

        $category = SourceCategory::query()->create([
            'source_id' => $sourceId,
            'type' => 'collection',
            'name' => 'Pirštinės',
            'handle' => 'pirstines',
        ]);

        $this->assertTrue($category->is(SourceCategory::findForMapping('collection', 'pirstines')));
        $this->assertTrue($category->is(SourceCategory::findForMapping('collection', 'Pirštinės')));
        $this->assertNull(SourceCategory::findForMapping('collection', 'missing'));
    }
}
