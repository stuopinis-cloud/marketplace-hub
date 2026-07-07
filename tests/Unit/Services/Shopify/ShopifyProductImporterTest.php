<?php

namespace Tests\Unit\Services\Shopify;

use App\Enums\ProductStatus;
use App\Enums\SyncJobItemStatus;
use App\Enums\SyncJobStatus;
use App\Enums\VarleExportStatus;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Models\InventoryLevel;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Source;
use App\Models\SyncJob;
use App\Models\SyncJobItem;
use App\Services\Shopify\ShopifyClient;
use App\Services\Shopify\ShopifyImportOptions;
use App\Services\Shopify\ShopifyProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\ShopifyProductFixtures;
use Tests\TestCase;

class ShopifyProductImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_importer_creates_products_variants_images_inventory_and_sync_job(): void
    {
        $this->mockProductImportSequence([
            ShopifyProductFixtures::productsResponse([
                ShopifyProductFixtures::product(['totalVariants' => 1]),
            ]),
            ShopifyProductFixtures::productVariantsResponse([
                ShopifyProductFixtures::variant(),
            ]),
        ]);

        $result = $this->makeImporter()->import();

        $this->assertSame(1, $result->productsImported);
        $this->assertSame(1, $result->variantsImported);
        $this->assertSame(0, $result->failedItems);

        $source = Source::query()->where('type', 'shopify')->first();
        $this->assertNotNull($source);
        $this->assertSame('Shopify', $source->name);

        $product = Product::query()->where('external_id', '1001')->first();
        $this->assertNotNull($product);
        $this->assertSame('Test Product', $product->title);
        $this->assertSame(ProductStatus::Active, $product->status);
        $this->assertNotNull($product->imported_at);

        $variant = ProductVariant::query()->where('external_id', '2001')->first();
        $this->assertNotNull($variant);
        $this->assertSame('SKU-2001', $variant->sku);
        $this->assertSame('https://cdn.shopify.com/variant-image.jpg', $variant->image_url);
        $this->assertSame('Variant front', $variant->image_alt);
        $this->assertSame('9001', $variant->image_external_id);
        $this->assertSame('M', $variant->option1);
        $this->assertSame('Black', $variant->option2);
        $this->assertSame('Size', $variant->option1_name);
        $this->assertSame('M', $variant->option1_value);
        $this->assertSame('Color', $variant->option2_name);
        $this->assertSame('Black', $variant->option2_value);

        $this->assertSame('Size', data_get($product->raw_payload, 'options.0.name'));
        $this->assertSame('Color', data_get($product->raw_payload, 'options.1.name'));

        $this->assertDatabaseHas('product_images', [
            'product_id' => $product->id,
            'url' => 'https://cdn.shopify.com/image-1.jpg',
            'alt' => 'Front',
        ]);

        $this->assertDatabaseHas('inventory_levels', [
            'variant_id' => $variant->id,
            'warehouse_name' => 'Shopify',
            'quantity' => 8,
        ]);

        $syncJob = SyncJob::query()->findOrFail($result->syncJobId);
        $this->assertSame(SyncJobStatus::Completed, $syncJob->status);
        $this->assertSame(1, $syncJob->total_items);
        $this->assertSame(1, $syncJob->success_items);
        $this->assertSame(0, $syncJob->failed_items);
    }

    public function test_importer_paginates_variants_per_product_and_imports_all_pages(): void
    {
        config(['shopify.variant_page_size' => 2]);

        $pageOneVariants = [
            ShopifyProductFixtures::variant([
                'id' => 'gid://shopify/ProductVariant/2001',
                'sku' => 'SKU-2001',
                'selectedOptions' => [
                    ['name' => 'Spalva', 'value' => 'Juoda'],
                    ['name' => 'Dydis', 'value' => 'S'],
                ],
            ]),
            ShopifyProductFixtures::variant([
                'id' => 'gid://shopify/ProductVariant/2002',
                'sku' => 'SKU-2002',
                'selectedOptions' => [
                    ['name' => 'Spalva', 'value' => 'Juoda'],
                    ['name' => 'Dydis', 'value' => 'M'],
                ],
            ]),
        ];

        $pageTwoVariant = ShopifyProductFixtures::variant([
            'id' => 'gid://shopify/ProductVariant/2003',
            'sku' => 'SKU-2003',
            'selectedOptions' => [
                ['name' => 'Spalva', 'value' => 'Juoda'],
                ['name' => 'Dydis', 'value' => 'L'],
            ],
        ]);
        $pageTwoVariant['inventoryItem'] = [
            'measurement' => [
                'weight' => [
                    'value' => 0.5,
                    'unit' => 'KILOGRAMS',
                ],
            ],
            'inventoryLevels' => [
                'nodes' => [
                    [
                        'quantities' => [
                            ['name' => 'available', 'quantity' => 99],
                        ],
                    ],
                ],
            ],
        ];

        $this->mockProductImportSequence([
            ShopifyProductFixtures::productsResponse([
                ShopifyProductFixtures::product([
                    'handle' => 'kelnes-helikon-tex-mcdu',
                    'totalVariants' => 3,
                    'options' => [
                        ['name' => 'Spalva', 'values' => ['Juoda']],
                        ['name' => 'Dydis', 'values' => ['S', 'M', 'L']],
                        ['name' => 'Ilgis', 'values' => ['Standartinis']],
                    ],
                ]),
            ]),
            ShopifyProductFixtures::productVariantsResponse($pageOneVariants, hasNextPage: true, endCursor: 'cursor-1'),
            ShopifyProductFixtures::productVariantsResponse([$pageTwoVariant]),
        ]);

        $result = $this->makeImporter()->import();

        $this->assertSame(1, $result->productsImported);
        $this->assertSame(3, $result->variantsImported);
        $this->assertSame(3, ProductVariant::query()->count());

        $lastVariant = ProductVariant::query()->where('external_id', '2003')->firstOrFail();
        $this->assertSame('Spalva', $lastVariant->option1_name);
        $this->assertSame('Juoda', $lastVariant->option1_value);
        $this->assertSame('Dydis', $lastVariant->option2_name);
        $this->assertSame('L', $lastVariant->option2_value);

        $inventory = InventoryLevel::query()->where('variant_id', $lastVariant->id)->firstOrFail();
        $this->assertSame(99, $inventory->quantity);
    }

    public function test_importer_updates_existing_products_and_inventory(): void
    {
        $updatedVariant = ShopifyProductFixtures::variant();
        $updatedVariant['inventoryItem']['inventoryLevels']['nodes'] = [
            [
                'quantities' => [
                    ['name' => 'available', 'quantity' => 12],
                ],
            ],
        ];

        $this->mockProductImportSequence([
            ShopifyProductFixtures::productsResponse([
                ShopifyProductFixtures::product(['title' => 'Original Title']),
            ]),
            ShopifyProductFixtures::productVariantsResponse([$updatedVariant]),
            ShopifyProductFixtures::productsResponse([
                ShopifyProductFixtures::product(['title' => 'Updated Title']),
            ]),
            ShopifyProductFixtures::productVariantsResponse([$updatedVariant]),
        ]);

        $importer = $this->makeImporter();

        $importer->import();
        $importer->import();

        $this->assertSame(1, Product::query()->count());
        $this->assertSame(1, ProductVariant::query()->count());
        $this->assertSame(1, ProductImage::query()->count());

        $product = Product::query()->firstOrFail();
        $this->assertSame('Updated Title', $product->title);

        $inventory = InventoryLevel::query()->firstOrFail();
        $this->assertSame(12, $inventory->quantity);
    }

    public function test_importer_records_product_level_failures(): void
    {
        $this->mockProductImportSequence([
            ShopifyProductFixtures::productsResponse([
                ShopifyProductFixtures::product(),
            ]),
            ShopifyProductFixtures::productVariantsResponse([
                ShopifyProductFixtures::variant(['id' => null]),
            ]),
        ]);

        $result = $this->makeImporter()->import();

        $this->assertSame(0, $result->productsImported);
        $this->assertSame(1, $result->failedItems);

        $syncJob = SyncJob::query()->findOrFail($result->syncJobId);
        $this->assertSame(SyncJobStatus::Failed, $syncJob->status);
        $this->assertSame(1, $syncJob->items()->count());
    }

    public function test_importer_stores_collections_product_type_and_tags_as_source_categories(): void
    {
        $this->mockProductImportSequence([
            ShopifyProductFixtures::productsResponse([
                ShopifyProductFixtures::product([
                    'productType' => 'Šarvinės liemenės',
                    'tags' => ['tactical', 'vest'],
                    'collections' => [
                        'nodes' => [
                            [
                                'id' => 'gid://shopify/Collection/3001',
                                'title' => 'Tactical Vests',
                                'handle' => 'tactical-vests',
                            ],
                        ],
                    ],
                ]),
            ]),
            ShopifyProductFixtures::productVariantsResponse([
                ShopifyProductFixtures::variant(),
            ]),
        ]);

        $this->makeImporter()->import();

        $product = Product::query()->where('external_id', '1001')->firstOrFail();
        $source = Source::query()->where('type', 'shopify')->firstOrFail();

        $this->assertDatabaseHas('source_categories', [
            'source_id' => $source->id,
            'type' => 'collection',
            'external_id' => '3001',
            'handle' => 'tactical-vests',
        ]);

        $this->assertDatabaseHas('source_categories', [
            'source_id' => $source->id,
            'type' => 'product_type',
            'name' => 'Šarvinės liemenės',
        ]);

        $this->assertDatabaseHas('source_categories', [
            'source_id' => $source->id,
            'type' => 'tag',
            'name' => 'tactical',
        ]);

        $this->assertSame(4, $product->sourceCategories()->count());
        $this->assertTrue($product->sourceCategories()->where('type', 'collection')->exists());
        $this->assertTrue($product->sourceCategories()->where('type', 'product_type')->exists());
        $this->assertTrue($product->sourceCategories()->where('type', 'tag')->exists());
    }

    public function test_importer_syncs_removed_source_categories_on_reimport(): void
    {
        $firstProduct = ShopifyProductFixtures::product();
        $firstProduct['tags'] = ['old-tag', 'keep-tag'];

        $secondProduct = ShopifyProductFixtures::product();
        $secondProduct['tags'] = ['keep-tag'];

        $this->mockProductImportSequence([
            ShopifyProductFixtures::productsResponse([$firstProduct]),
            ShopifyProductFixtures::productVariantsResponse([ShopifyProductFixtures::variant()]),
            ShopifyProductFixtures::productsResponse([$secondProduct]),
            ShopifyProductFixtures::productVariantsResponse([ShopifyProductFixtures::variant()]),
        ]);

        $importer = $this->makeImporter();
        $importer->import();
        $importer->import();

        $product = Product::query()->where('external_id', '1001')->firstOrFail();

        $this->assertSame(1, $product->sourceCategories()->where('type', 'tag')->count());
        $this->assertTrue($product->sourceCategories()->where('name', 'keep-tag')->exists());
        $this->assertFalse($product->sourceCategories()->where('name', 'old-tag')->exists());
    }

    public function test_importer_surfaces_query_cost_guidance(): void
    {
        $this->mock(ShopifyClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('query')
                ->once()
                ->andThrow(ShopifyGraphQlException::fromErrors([
                    ['message' => 'Query cost is 1045, which exceeds the single query max cost limit 1000.'],
                ]));
        });

        try {
            $this->makeImporter()->import();
            $this->fail('Expected ShopifyGraphQlException was not thrown.');
        } catch (ShopifyGraphQlException $exception) {
            $this->assertStringContainsString('Query cost is 1045', $exception->getMessage());
            $this->assertStringContainsString('SHOPIFY_PRODUCT_PAGE_SIZE', $exception->getMessage());
            $this->assertStringContainsString('Bulk Operations', $exception->getMessage());
        }
    }

    public function test_importer_sets_new_products_to_pending_review_and_records_info_item(): void
    {
        $this->mockProductImportSequence([
            ShopifyProductFixtures::productsResponse([
                ShopifyProductFixtures::product(),
            ]),
            ShopifyProductFixtures::productVariantsResponse([
                ShopifyProductFixtures::variant(),
            ]),
        ]);

        $result = $this->makeImporter()->import();

        $product = Product::query()->where('external_id', '1001')->firstOrFail();
        $this->assertSame(VarleExportStatus::PendingReview, $product->varle_export_status);
        $this->assertSame(1, $result->newProductsCount);
        $this->assertSame(1, $result->pendingReviewProductsCount);

        $syncJob = SyncJob::query()->findOrFail($result->syncJobId);
        $this->assertSame(1, data_get($syncJob->context, 'new_products_count'));
        $this->assertSame(1, data_get($syncJob->context, 'pending_review_products_count'));

        $infoItem = SyncJobItem::query()->where('sync_job_id', $syncJob->id)->firstOrFail();
        $this->assertSame(SyncJobItemStatus::Info, $infoItem->status);
        $this->assertSame('New product imported, pending Varle review', $infoItem->message);
    }

    public function test_importer_preserves_existing_varle_export_status_on_reimport(): void
    {
        $this->mockProductImportSequence([
            ShopifyProductFixtures::productsResponse([
                ShopifyProductFixtures::product(),
            ]),
            ShopifyProductFixtures::productVariantsResponse([
                ShopifyProductFixtures::variant(),
            ]),
            ShopifyProductFixtures::productsResponse([
                ShopifyProductFixtures::product(['title' => 'Updated Title']),
            ]),
            ShopifyProductFixtures::productVariantsResponse([
                ShopifyProductFixtures::variant(),
            ]),
        ]);

        $importer = $this->makeImporter();
        $importer->import();

        Product::query()->where('external_id', '1001')->update([
            'varle_export_status' => VarleExportStatus::Include,
        ]);

        $result = $importer->import();

        $product = Product::query()->where('external_id', '1001')->firstOrFail();
        $this->assertSame(VarleExportStatus::Include, $product->varle_export_status);
        $this->assertSame(0, $result->newProductsCount);
        $this->assertSame(1, $result->updatedProductsCount);
    }

    public function test_importer_respects_limit_option(): void
    {
        $this->mockProductImportSequence([
            ShopifyProductFixtures::productsResponse([
                ShopifyProductFixtures::product(['id' => 'gid://shopify/Product/1001', 'handle' => 'one']),
                ShopifyProductFixtures::product(['id' => 'gid://shopify/Product/1002', 'handle' => 'two']),
                ShopifyProductFixtures::product(['id' => 'gid://shopify/Product/1003', 'handle' => 'three']),
            ]),
            ShopifyProductFixtures::productVariantsResponse([
                ShopifyProductFixtures::variant(['id' => 'gid://shopify/ProductVariant/2001']),
            ]),
            ShopifyProductFixtures::productVariantsResponse([
                ShopifyProductFixtures::variant(['id' => 'gid://shopify/ProductVariant/2002']),
            ]),
        ]);

        $result = $this->makeImporter()->import(new ShopifyImportOptions(limit: 2));

        $this->assertSame(2, $result->productsImported);
        $this->assertSame(2, Product::query()->count());
    }

    public function test_importer_respects_handle_option(): void
    {
        $this->mock(ShopifyClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('query')
                ->once()
                ->withArgs(function (string $query, array $variables): bool {
                    return str_contains($query, 'ImportActiveProducts')
                        && ($variables['query'] ?? null) === 'handle:single-handle';
                })
                ->andReturn(
                    ShopifyProductFixtures::productsResponse([
                        ShopifyProductFixtures::product(['handle' => 'single-handle']),
                    ]),
                );

            $mock->shouldReceive('query')
                ->once()
                ->andReturn(
                    ShopifyProductFixtures::productVariantsResponse([
                        ShopifyProductFixtures::variant(),
                    ]),
                );
        });

        $result = $this->makeImporter()->import(new ShopifyImportOptions(handle: 'single-handle'));

        $this->assertSame(1, $result->productsImported);
    }

    public function test_importer_marks_sync_job_failed_when_shopify_query_throws(): void
    {
        $this->mock(ShopifyClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('query')
                ->once()
                ->andThrow(new ShopifyGraphQlException('Shopify API unavailable'));
        });

        try {
            $this->makeImporter()->import();
            $this->fail('Expected ShopifyGraphQlException was not thrown.');
        } catch (ShopifyGraphQlException) {
            // expected
        }

        $syncJob = SyncJob::query()->latest('id')->firstOrFail();
        $this->assertSame(SyncJobStatus::Failed, $syncJob->status);
        $this->assertNotNull($syncJob->finished_at);
        $this->assertSame('Shopify API unavailable', $syncJob->error_message);
    }

    public function test_importer_stops_cleanly_when_cancellation_is_requested(): void
    {
        $this->mock(ShopifyClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('query')
                ->andReturnUsing(function (string $query, array $variables = []) {
                    if (str_contains($query, 'ProductVariants')) {
                        SyncJob::query()->latest('id')->first()?->update([
                            'cancel_requested_at' => now(),
                        ]);
                    }

                    if (str_contains($query, 'ImportActiveProducts')) {
                        return ShopifyProductFixtures::productsResponse([
                            ShopifyProductFixtures::product(['id' => 'gid://shopify/Product/1001', 'handle' => 'first']),
                            ShopifyProductFixtures::product(['id' => 'gid://shopify/Product/1002', 'handle' => 'second']),
                        ]);
                    }

                    return ShopifyProductFixtures::productVariantsResponse([
                        ShopifyProductFixtures::variant(),
                    ]);
                });
        });

        $result = $this->makeImporter()->import();

        $this->assertSame(1, $result->productsImported);

        $syncJob = SyncJob::query()->findOrFail($result->syncJobId);
        $this->assertSame(SyncJobStatus::Cancelled, $syncJob->status);
        $this->assertNotNull($syncJob->cancelled_at);
        $this->assertNotNull($syncJob->finished_at);
    }

    /**
     * @param  array<int, array<string, mixed>>  $responses
     */
    private function mockProductImportSequence(array $responses): void
    {
        $this->mock(ShopifyClient::class, function (MockInterface $mock) use ($responses): void {
            $mock->shouldReceive('query')
                ->times(count($responses))
                ->andReturn(...$responses);
        });
    }

    private function makeImporter(): ShopifyProductImporter
    {
        return $this->app->make(ShopifyProductImporter::class);
    }
}
