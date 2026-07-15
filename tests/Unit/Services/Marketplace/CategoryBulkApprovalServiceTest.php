<?php

namespace Tests\Unit\Services\Marketplace;

use App\Enums\ProductStatus;
use App\Enums\VarleExportStatus;
use App\Jobs\RefreshVarleReadinessJob;
use App\Models\Product;
use App\Models\Source;
use App\Models\SourceCategory;
use App\Services\Marketplace\CategoryBulkApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CategoryBulkApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_single_category_updates_all_connected_products(): void
    {
        $category = $this->createCategory('trousers');
        $product = $this->createProduct('SKU-1', VarleExportStatus::PendingReview);
        $category->products()->attach($product->id);

        $result = app(CategoryBulkApprovalService::class)->apply([$category->id], VarleExportStatus::Include, dispatchReadinessRefresh: false);

        $this->assertSame(1, $result->updatedCount);
        $this->assertSame(VarleExportStatus::Include, $product->fresh()->varle_export_status);
    }

    public function test_multiple_categories_update_unique_products_once(): void
    {
        $trousers = $this->createCategory('trousers');
        $combat = $this->createCategory('combat-trousers');
        $product = $this->createProduct('SKU-OVERLAP', VarleExportStatus::Exclude);
        $other = $this->createProduct('SKU-OTHER', VarleExportStatus::Auto);

        $trousers->products()->attach([$product->id, $other->id]);
        $combat->products()->attach($product->id);

        $result = app(CategoryBulkApprovalService::class)->apply(
            [$trousers->id, $combat->id],
            VarleExportStatus::Include,
            dispatchReadinessRefresh: false,
        );

        $this->assertSame(2, $result->updatedCount);
        $this->assertCount(2, $result->productIds);
        $this->assertSame(VarleExportStatus::Include, $product->fresh()->varle_export_status);
        $this->assertSame(VarleExportStatus::Include, $other->fresh()->varle_export_status);
    }

    public function test_preview_returns_status_breakdown(): void
    {
        $category = $this->createCategory('jackets');
        $include = $this->createProduct('IN-1', VarleExportStatus::Include);
        $exclude = $this->createProduct('EX-1', VarleExportStatus::Exclude);
        $category->products()->attach([$include->id, $exclude->id]);

        $preview = app(CategoryBulkApprovalService::class)->preview([$category->id]);

        $this->assertSame(1, $preview['category_count']);
        $this->assertSame(2, $preview['affected_product_count']);
        $this->assertSame(1, $preview['status_breakdown']['include']);
        $this->assertSame(1, $preview['status_breakdown']['exclude']);
    }

    public function test_apply_dispatches_background_readiness_refresh(): void
    {
        Bus::fake();

        $category = $this->createCategory('backpacks');
        $product = $this->createProduct('BP-1', VarleExportStatus::PendingReview);
        $category->products()->attach($product->id);

        $result = app(CategoryBulkApprovalService::class)->apply([$category->id], VarleExportStatus::Auto);

        $this->assertTrue($result->readinessQueued);
        Bus::assertDispatched(RefreshVarleReadinessJob::class);
    }

    public function test_all_export_status_actions_update_products(): void
    {
        $category = $this->createCategory('gear');
        $service = app(CategoryBulkApprovalService::class);

        foreach ([VarleExportStatus::Include, VarleExportStatus::Exclude, VarleExportStatus::PendingReview, VarleExportStatus::Auto] as $status) {
            $product = $this->createProduct('SKU-'.$status->value, VarleExportStatus::Auto);
            $category->products()->sync([$product->id]);

            $service->apply([$category->id], $status, dispatchReadinessRefresh: false);

            $this->assertSame($status, $product->fresh()->varle_export_status);
        }
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

    private function createProduct(string $sku, VarleExportStatus $status): Product
    {
        $source = Source::query()->firstOrCreate(
            ['type' => 'shopify', 'name' => 'Shopify'],
            ['enabled' => true, 'config' => []],
        );

        return Product::query()->create([
            'source_id' => $source->id,
            'external_id' => 'product-'.uniqid(),
            'title' => 'Product '.$sku,
            'handle' => 'product-'.uniqid(),
            'status' => ProductStatus::Active,
            'varle_export_status' => $status,
            'imported_at' => now(),
        ]);
    }
}
