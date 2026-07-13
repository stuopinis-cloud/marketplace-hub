<?php

namespace Tests\Feature\Filament;

use App\Enums\ProductStatus;
use App\Enums\VarleExportStatus;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Source;
use App\Models\User;
use App\Services\Marketplace\Varle\VarleReadinessService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\VarleCatalogFixtures;
use Tests\TestCase;

class ProductsTableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_issue_codes_render_as_badges(): void
    {
        $product = $this->createCachedProduct([
            'title' => 'Badge Product',
            'varle_is_ready' => false,
            'varle_issue_count' => 2,
            'varle_issue_codes' => ['missing_barcode', 'missing_category_mapping'],
        ]);

        Livewire::test(ListProducts::class)
            ->assertCanSeeTableRecords([$product])
            ->assertSee('Missing barcode')
            ->assertSee('Missing category mapping');
    }

    public function test_not_ready_product_is_visible_with_status_badge(): void
    {
        $product = $this->createCachedProduct([
            'title' => 'Blocked Product',
            'varle_is_ready' => false,
            'varle_issue_count' => 1,
            'varle_issue_codes' => ['pending_review'],
            'varle_readiness_cached_at' => now(),
        ]);

        Livewire::test(ListProducts::class)
            ->assertCanSeeTableRecords([$product])
            ->assertSee('Not ready');
    }

    public function test_filter_by_specific_issue_code(): void
    {
        $matching = $this->createCachedProduct([
            'title' => 'Stale Stock Product',
            'varle_issue_codes' => ['supplier_stock_stale'],
            'varle_issue_count' => 1,
        ]);

        $other = $this->createCachedProduct([
            'title' => 'Healthy Product',
            'varle_issue_codes' => ['missing_barcode'],
            'varle_issue_count' => 1,
        ]);

        Livewire::test(ListProducts::class)
            ->filterTable('issue_code', 'supplier_stock_stale')
            ->assertCanSeeTableRecords([$matching])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_filter_missing_barcode_issue(): void
    {
        $matching = $this->createCachedProduct([
            'title' => 'No Barcode Product',
            'varle_issue_codes' => ['missing_barcode'],
            'varle_issue_count' => 1,
        ]);

        $other = $this->createCachedProduct([
            'title' => 'Other Issue Product',
            'varle_issue_codes' => ['pending_review'],
            'varle_issue_count' => 1,
        ]);

        Livewire::test(ListProducts::class)
            ->filterTable('missing_barcode_issue')
            ->assertCanSeeTableRecords([$matching])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_filter_stale_supplier_stock(): void
    {
        $matching = $this->createCachedProduct([
            'title' => 'Stale Supplier Product',
            'varle_issue_codes' => ['supplier_stock_stale'],
            'varle_issue_count' => 1,
        ]);

        $other = $this->createCachedProduct([
            'title' => 'Fresh Supplier Product',
            'varle_issue_codes' => [],
            'varle_issue_count' => 0,
            'varle_is_ready' => true,
        ]);

        Livewire::test(ListProducts::class)
            ->filterTable('supplier_stock_stale')
            ->assertCanSeeTableRecords([$matching])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_view_issues_action_exists_and_modal_renders_issue_labels(): void
    {
        $variant = VarleCatalogFixtures::createExportableVariant(
            productOverrides: ['title' => 'Diagnostics Product'],
            variantOverrides: ['barcode' => null],
        );

        $product = $variant->product->fresh();
        $analysis = app(VarleReadinessService::class)->analyze($product);

        $html = view('filament.products.view-varle-issues', [
            'record' => $product->loadCount('variants'),
            'analysis' => $analysis,
        ])->render();

        $this->assertStringContainsString('Diagnostics Product', $html);
        $this->assertStringContainsString('Missing barcode', $html);
    }

    public function test_refresh_readiness_bulk_action_updates_cached_columns(): void
    {
        $variant = VarleCatalogFixtures::createExportableVariant(
            productOverrides: ['title' => 'Refresh Product'],
        );
        $product = $variant->product->fresh();

        Livewire::test(ListProducts::class)
            ->callTableBulkAction('refreshVarleReadiness', [$product]);

        $product->refresh();

        $this->assertNotNull($product->varle_readiness_cached_at);
        $this->assertSame('all_variants_have_barcode', $product->varle_barcode_status);
    }

    public function test_search_by_variant_sku(): void
    {
        $product = $this->createCachedProduct([
            'title' => 'Searchable Product',
            'handle' => 'searchable-product',
        ]);

        ProductVariant::query()->create([
            'product_id' => $product->id,
            'external_id' => 'variant-search',
            'sku' => 'UNIQUE-SKU-12345',
            'barcode' => '5901234123457',
            'title' => 'Default',
            'price' => 10,
        ]);

        $other = $this->createCachedProduct([
            'title' => 'Different Product',
            'handle' => 'different-product',
        ]);

        Livewire::test(ListProducts::class)
            ->searchTable('UNIQUE-SKU-12345')
            ->assertCanSeeTableRecords([$product])
            ->assertCanNotSeeTableRecords([$other]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createCachedProduct(array $overrides = []): Product
    {
        $source = Source::query()->firstOrCreate(
            ['type' => 'shopify', 'name' => 'Shopify'],
            ['enabled' => true, 'config' => []],
        );

        return Product::query()->create(array_merge([
            'source_id' => $source->id,
            'external_id' => 'product-'.uniqid(),
            'title' => 'Test Product',
            'handle' => 'test-product-'.uniqid(),
            'vendor' => 'Vendor Name',
            'status' => ProductStatus::Active,
            'varle_export_status' => VarleExportStatus::Auto,
            'varle_is_ready' => false,
            'varle_issue_count' => 0,
            'varle_issue_codes' => [],
            'varle_readiness_cached_at' => now(),
            'imported_at' => now(),
        ], $overrides));
    }
}
