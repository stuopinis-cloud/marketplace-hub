<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CategoryMappings\Pages\CreateCategoryMapping;
use App\Models\CategoryMapping;
use App\Models\MarketplaceChannel;
use App\Models\Source;
use App\Models\SourceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CategoryMappingFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_dropdown_product_type_selection_saves_source_value(): void
    {
        $channel = MarketplaceChannel::query()->create([
            'name' => 'Varle.lt',
            'type' => 'varle',
            'enabled' => true,
            'config' => [],
        ]);

        $source = Source::query()->create(['type' => 'shopify', 'name' => 'Shopify', 'enabled' => true, 'config' => []]);
        $category = SourceCategory::query()->create([
            'source_id' => $source->id,
            'type' => 'product_type',
            'external_id' => '200',
            'name' => 'Šarvinės liemenės',
            'handle' => null,
        ]);

        Livewire::test(CreateCategoryMapping::class)
            ->fillForm([
                'marketplace_channel_id' => $channel->id,
                'source_type' => 'product_type',
                'source_category_id' => $category->id,
                'target_category_path' => 'Test -> Category',
                'priority' => 100,
                'enabled' => true,
                'export_enabled' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('Šarvinės liemenės', CategoryMapping::query()->value('source_value'));
    }

    public function test_dropdown_collection_selection_saves_source_value(): void
    {
        $channel = MarketplaceChannel::query()->create([
            'name' => 'Varle.lt',
            'type' => 'varle',
            'enabled' => true,
            'config' => [],
        ]);

        $source = Source::query()->create(['type' => 'shopify', 'name' => 'Shopify', 'enabled' => true, 'config' => []]);
        $category = SourceCategory::query()->create([
            'source_id' => $source->id,
            'type' => 'collection',
            'external_id' => '100',
            'name' => 'Pirštinės',
            'handle' => 'pirstines',
        ]);

        Livewire::test(CreateCategoryMapping::class)
            ->fillForm([
                'marketplace_channel_id' => $channel->id,
                'source_type' => 'collection',
                'source_category_id' => $category->id,
                'target_category_path' => 'Test -> Category',
                'priority' => 100,
                'enabled' => true,
                'export_enabled' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $mapping = CategoryMapping::query()->firstOrFail();
        $this->assertSame('pirstines', $mapping->source_value);
    }

    public function test_manual_source_value_saves_correctly(): void
    {
        $channel = MarketplaceChannel::query()->create([
            'name' => 'Varle.lt',
            'type' => 'varle',
            'enabled' => true,
            'config' => [],
        ]);

        Livewire::test(CreateCategoryMapping::class)
            ->fillForm([
                'marketplace_channel_id' => $channel->id,
                'source_type' => 'manual',
                'source_value' => 'Manual Category',
                'target_category_path' => 'Manual -> Category',
                'priority' => 100,
                'enabled' => true,
                'export_enabled' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('Manual Category', CategoryMapping::query()->value('source_value'));
    }

    public function test_missing_source_value_fails_validation_before_db_insert(): void
    {
        $channel = MarketplaceChannel::query()->create([
            'name' => 'Varle.lt',
            'type' => 'varle',
            'enabled' => true,
            'config' => [],
        ]);

        Livewire::test(CreateCategoryMapping::class)
            ->fillForm([
                'marketplace_channel_id' => $channel->id,
                'source_type' => 'collection',
                'target_category_path' => 'Test -> Category',
                'priority' => 100,
                'enabled' => true,
                'export_enabled' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['source_category_id']);

        $this->assertSame(0, CategoryMapping::query()->count());
    }
}
