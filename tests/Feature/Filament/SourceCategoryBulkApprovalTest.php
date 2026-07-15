<?php

namespace Tests\Feature\Filament;

use App\Enums\ProductStatus;
use App\Enums\VarleExportStatus;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Jobs\RefreshVarleReadinessJob;
use App\Models\Product;
use App\Models\Source;
use App\Models\SourceCategory;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

class SourceCategoryBulkApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_products_table_filters_by_multiple_categories(): void
    {
        $trousers = $this->createCategory('trousers');
        $jackets = $this->createCategory('jackets');

        $inTrousers = $this->createProduct('T-1');
        $inJackets = $this->createProduct('J-1');
        $inBoth = $this->createProduct('B-1');
        $other = $this->createProduct('O-1');

        $trousers->products()->attach([$inTrousers->id, $inBoth->id]);
        $jackets->products()->attach([$inJackets->id, $inBoth->id]);

        Livewire::test(ListProducts::class)
            ->filterTable('source_categories', [
                'category_ids' => [$trousers->id, $jackets->id],
            ])
            ->assertCanSeeTableRecords([$inTrousers, $inJackets, $inBoth])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_source_categories_bulk_include_dispatches_readiness_refresh(): void
    {
        Bus::fake();

        $category = $this->createCategory('packs');
        $product = $this->createProduct('P-1', VarleExportStatus::PendingReview);
        $category->products()->attach($product->id);

        Livewire::test(\App\Filament\Resources\SourceCategories\Pages\ListSourceCategories::class)
            ->callTableBulkAction('includeInVarle', [$category]);

        $this->assertSame(VarleExportStatus::Include, $product->fresh()->varle_export_status);
        Bus::assertDispatched(RefreshVarleReadinessJob::class);
    }

    private function createCategory(string $handle): SourceCategory
    {
        $source = Source::query()->firstOrCreate(
            ['type' => 'shopify', 'name' => 'Shopify'],
            ['enabled' => true, 'config' => []],
        );

        return SourceCategory::query()->create([
            'source_id' => $source->id,
            'type' => 'collection',
            'external_id' => 'cat-'.uniqid(),
            'name' => ucfirst($handle),
            'handle' => $handle,
        ]);
    }

    private function createProduct(string $suffix, VarleExportStatus $status = VarleExportStatus::Auto): Product
    {
        $source = Source::query()->firstOrCreate(
            ['type' => 'shopify', 'name' => 'Shopify'],
            ['enabled' => true, 'config' => []],
        );

        return Product::query()->create([
            'source_id' => $source->id,
            'external_id' => 'product-'.$suffix.'-'.uniqid(),
            'title' => 'Product '.$suffix,
            'handle' => 'product-'.$suffix.'-'.uniqid(),
            'status' => ProductStatus::Active,
            'varle_export_status' => $status,
            'imported_at' => now(),
        ]);
    }
}
