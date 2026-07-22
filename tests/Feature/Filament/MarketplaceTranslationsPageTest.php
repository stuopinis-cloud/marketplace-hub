<?php

namespace Tests\Feature\Filament;

use App\Enums\MarketplaceTranslationStatus;
use App\Enums\ProductStatus;
use App\Filament\Resources\MarketplaceTranslations\Pages\ListMarketplaceTranslations;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\MarketplaceTranslation;
use App\Models\Product;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MarketplaceTranslationsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_translations_page_renders_and_can_approve_edit_translations(): void
    {
        $this->actingAs(User::factory()->create());

        $source = Source::query()->create([
            'type' => 'shopify',
            'name' => 'Shopify',
            'enabled' => true,
            'config' => [],
        ]);

        $product = Product::query()->create([
            'source_id' => $source->id,
            'external_id' => 'p-1',
            'title' => 'Kuprinė',
            'vendor' => 'Vendor',
            'status' => ProductStatus::Active,
            'imported_at' => now(),
        ]);

        $translation = MarketplaceTranslation::query()->create([
            'translatable_type' => $product->getMorphClass(),
            'translatable_id' => $product->id,
            'marketplace' => 'ebay',
            'locale' => 'en',
            'field' => 'title',
            'source_text_hash' => MarketplaceTranslation::hashSource('Kuprinė'),
            'source_text' => 'Kuprinė',
            'translated_text' => 'Backpack',
            'status' => MarketplaceTranslationStatus::AutoTranslated,
            'provider' => 'openai',
        ]);

        Livewire::test(ListMarketplaceTranslations::class)
            ->assertCanSeeTableRecords([$translation])
            ->callTableAction('approve', $translation);

        $this->assertSame(MarketplaceTranslationStatus::Approved, $translation->fresh()->status);

        Livewire::test(ListMarketplaceTranslations::class)
            ->callTableAction('edit', $translation, data: [
                'translated_text' => 'Tactical Backpack',
                'status' => MarketplaceTranslationStatus::Approved->value,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame('Tactical Backpack', $translation->fresh()->translated_text);

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->assertSuccessful();
    }
}
