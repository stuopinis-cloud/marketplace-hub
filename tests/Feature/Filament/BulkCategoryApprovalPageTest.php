<?php

namespace Tests\Feature\Filament;

use App\Enums\ProductStatus;
use App\Enums\VarleExportStatus;
use App\Filament\Pages\BulkCategoryApproval;
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

class BulkCategoryApprovalPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_single_selected_category_bulk_include_works(): void
    {
        Bus::fake();

        $category = $this->createCategory('trousers');
        $product = $this->createProduct('T-1', VarleExportStatus::PendingReview);
        $category->products()->attach($product->id);

        Livewire::test(BulkCategoryApproval::class)
            ->callTableBulkAction('includeInVarle', [$category]);

        $this->assertSame(VarleExportStatus::Include, $product->fresh()->varle_export_status);
        Bus::assertDispatched(RefreshVarleReadinessJob::class);
    }

    public function test_multiple_selected_categories_bulk_exclude_works(): void
    {
        Bus::fake();

        $trousers = $this->createCategory('trousers');
        $jackets = $this->createCategory('jackets');
        $inTrousers = $this->createProduct('T-1', VarleExportStatus::Include);
        $inJackets = $this->createProduct('J-1', VarleExportStatus::Include);

        $trousers->products()->attach($inTrousers->id);
        $jackets->products()->attach($inJackets->id);

        Livewire::test(BulkCategoryApproval::class)
            ->callTableBulkAction('excludeFromVarle', [$trousers, $jackets]);

        $this->assertSame(VarleExportStatus::Exclude, $inTrousers->fresh()->varle_export_status);
        $this->assertSame(VarleExportStatus::Exclude, $inJackets->fresh()->varle_export_status);
    }

    public function test_overlapping_categories_update_distinct_products_once(): void
    {
        $trousers = $this->createCategory('trousers');
        $combat = $this->createCategory('combat-trousers');
        $shared = $this->createProduct('SHARED', VarleExportStatus::Auto);
        $other = $this->createProduct('OTHER', VarleExportStatus::Auto);

        $trousers->products()->attach([$shared->id, $other->id]);
        $combat->products()->attach($shared->id);

        Livewire::test(BulkCategoryApproval::class)
            ->callTableBulkAction('includeInVarle', [$trousers, $combat]);

        $this->assertSame(VarleExportStatus::Include, $shared->fresh()->varle_export_status);
        $this->assertSame(VarleExportStatus::Include, $other->fresh()->varle_export_status);
    }

    public function test_confirmation_modal_preserves_selected_records(): void
    {
        $category = $this->createCategory('backpacks');
        $product = $this->createProduct('BP-1', VarleExportStatus::PendingReview);
        $category->products()->attach($product->id);

        Livewire::test(BulkCategoryApproval::class)
            ->mountTableBulkAction('includeInVarle', [$category])
            ->assertTableBulkActionMounted('includeInVarle')
            ->callMountedTableBulkAction();

        $this->assertSame(VarleExportStatus::Include, $product->fresh()->varle_export_status);
    }

    public function test_empty_selection_shows_warning(): void
    {
        Livewire::test(BulkCategoryApproval::class)
            ->callTableBulkAction('includeInVarle', [])
            ->assertNotified('No categories selected');
    }

    public function test_header_category_picker_works_independently(): void
    {
        Bus::fake();

        $category = $this->createCategory('packs');
        $product = $this->createProduct('P-1', VarleExportStatus::Auto);
        $category->products()->attach($product->id);

        Livewire::test(BulkCategoryApproval::class)
            ->callAction('applyByCategoryPicker', [
                'category_ids' => [$category->id],
                'target_status' => VarleExportStatus::Include->value,
            ]);

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
            'name' => ucfirst(str_replace('-', ' ', $handle)),
            'handle' => $handle,
        ]);
    }

    private function createProduct(string $suffix, VarleExportStatus $status): Product
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
