<?php

namespace App\Services\Shopify;

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
use App\Models\SourceCategory;
use App\Models\SyncJob;
use App\Models\SyncJobItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ShopifyProductImporter
{
    private const string WAREHOUSE_NAME = 'Shopify';

    private int $productsImported = 0;

    private int $variantsImported = 0;

    private int $imagesImported = 0;

    private int $failedItems = 0;

    private int $newProductsCount = 0;

    private int $updatedProductsCount = 0;

    private int $pendingReviewProductsCount = 0;

    private int $unpublishedProductsCount = 0;

    public function __construct(
        private readonly ShopifyClient $client,
    ) {}

    public function import(): ShopifyImportResult
    {
        $this->resetCounters();

        $source = $this->resolveSource();
        $syncJob = $this->startSyncJob();

        try {
            foreach ($this->fetchProductPages() as $products) {
                $syncJob->increment('total_items', count($products));

                foreach ($products as $productPayload) {
                    $this->importProduct($source, $syncJob, $productPayload);
                }
            }

            $this->finishSyncJob($syncJob);

            return new ShopifyImportResult(
                syncJobId: $syncJob->id,
                productsImported: $this->productsImported,
                variantsImported: $this->variantsImported,
                failedItems: $this->failedItems,
                newProductsCount: $this->newProductsCount,
                updatedProductsCount: $this->updatedProductsCount,
                pendingReviewProductsCount: $this->pendingReviewProductsCount,
                unpublishedProductsCount: $this->unpublishedProductsCount,
            );
        } catch (Throwable $exception) {
            $this->failSyncJob($syncJob, $exception);

            throw $exception;
        }
    }

    /**
     * @return \Generator<int, array<int, array<string, mixed>>>
     */
    private function fetchProductPages(): \Generator
    {
        $cursor = null;

        do {
            try {
                $response = $this->client->query(
                    $this->productsQuery(),
                    ['cursor' => $cursor],
                );
            } catch (ShopifyGraphQlException $exception) {
                throw $exception->withQueryCostGuidance();
            }

            $connection = $response['data']['products'] ?? null;

            if (! is_array($connection)) {
                break;
            }

            $nodes = $connection['nodes'] ?? [];

            if (is_array($nodes) && $nodes !== []) {
                yield $nodes;
            }

            $pageInfo = $connection['pageInfo'] ?? [];
            $hasNextPage = (bool) ($pageInfo['hasNextPage'] ?? false);
            $cursor = $hasNextPage ? ($pageInfo['endCursor'] ?? null) : null;
        } while ($cursor !== null);
    }

    /**
     * @param  array<string, mixed>  $productPayload
     */
    private function importProduct(Source $source, SyncJob $syncJob, array $productPayload): void
    {
        $externalId = $this->extractIdFromGid((string) ($productPayload['id'] ?? ''));

        if ($externalId === '') {
            $this->recordProductFailure($syncJob, null, null, 'Shopify product is missing an ID.', $productPayload);

            return;
        }

        try {
            $importResult = DB::transaction(function () use ($source, $productPayload, $externalId): array {
                $result = $this->upsertProduct($source, $productPayload, $externalId);
                $product = $result['product'];
                $this->syncVariants($product, (string) ($productPayload['id'] ?? ''));
                $this->syncImages($product, $productPayload);
                $this->syncSourceCategories($source, $product, $productPayload);

                return $result;
            });

            if ($importResult['is_new']) {
                $this->newProductsCount++;
                $this->pendingReviewProductsCount++;

                SyncJobItem::query()->create([
                    'sync_job_id' => $syncJob->id,
                    'product_id' => $importResult['product']->id,
                    'status' => SyncJobItemStatus::Info,
                    'message' => 'New product imported, pending Varle review',
                ]);
            } else {
                $this->updatedProductsCount++;
            }

            if ($importResult['product']->status !== ProductStatus::Active) {
                $this->unpublishedProductsCount++;
            }

            $syncJob->increment('success_items');
            $this->productsImported++;
        } catch (Throwable $exception) {
            $this->recordProductFailure(
                $syncJob,
                $externalId,
                $this->firstSkuFromPayload($productPayload),
                $exception->getMessage(),
                $productPayload,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $productPayload
     * @return array{product: Product, is_new: bool}
     */
    private function upsertProduct(Source $source, array $productPayload, string $externalId): array
    {
        $product = Product::query()->updateOrCreate(
            [
                'source_id' => $source->id,
                'external_id' => $externalId,
            ],
            [
                'title' => (string) ($productPayload['title'] ?? 'Untitled product'),
                'description_html' => $productPayload['descriptionHtml'] ?? null,
                'vendor' => $productPayload['vendor'] ?? null,
                'product_type' => $productPayload['productType'] ?? null,
                'handle' => $productPayload['handle'] ?? null,
                'status' => $this->mapProductStatus((string) ($productPayload['status'] ?? 'ACTIVE')),
                'raw_payload' => $productPayload,
                'imported_at' => now(),
            ],
        );

        $isNew = $product->wasRecentlyCreated;

        if ($isNew && $product->varle_export_status === null) {
            $product->update(['varle_export_status' => VarleExportStatus::PendingReview]);
            $product->refresh();
        }

        return [
            'product' => $product,
            'is_new' => $isNew,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchAllVariantsForProduct(string $shopifyProductGid): array
    {
        $productGid = $this->normalizeProductGid($shopifyProductGid);
        $pageSize = (int) config('shopify.variant_page_size', 50);
        $variants = [];
        $cursor = null;

        do {
            try {
                $response = $this->client->query(
                    $this->productVariantsQuery(),
                    [
                        'productId' => $productGid,
                        'cursor' => $cursor,
                        'first' => $pageSize,
                    ],
                );
            } catch (ShopifyGraphQlException $exception) {
                throw $exception->withQueryCostGuidance();
            }

            $connection = $response['data']['product']['variants'] ?? null;

            if (! is_array($connection)) {
                break;
            }

            $nodes = $connection['nodes'] ?? [];

            if (is_array($nodes)) {
                foreach ($nodes as $node) {
                    if (is_array($node)) {
                        $variants[] = $node;
                    }
                }
            }

            $pageInfo = $connection['pageInfo'] ?? [];
            $hasNextPage = (bool) ($pageInfo['hasNextPage'] ?? false);
            $cursor = $hasNextPage ? ($pageInfo['endCursor'] ?? null) : null;
        } while ($cursor !== null);

        return $variants;
    }

    private function syncVariants(Product $product, string $shopifyProductGid): void
    {
        if ($shopifyProductGid === '') {
            return;
        }

        foreach ($this->fetchAllVariantsForProduct($shopifyProductGid) as $variantPayload) {
            $variant = $this->upsertVariant($product, $variantPayload);
            $this->syncInventory($variant, $variantPayload);
            $this->variantsImported++;
        }
    }

    /**
     * @param  array<string, mixed>  $variantPayload
     */
    private function upsertVariant(Product $product, array $variantPayload): ProductVariant
    {
        $externalId = $this->extractIdFromGid((string) ($variantPayload['id'] ?? ''));

        if ($externalId === '') {
            throw new \InvalidArgumentException('Shopify variant is missing an ID.');
        }

        $options = $this->mapOptionFields($variantPayload['selectedOptions'] ?? []);
        $weight = $this->extractWeight($variantPayload);

        return ProductVariant::query()->updateOrCreate(
            [
                'product_id' => $product->id,
                'external_id' => $externalId,
            ],
            [
                'sku' => $variantPayload['sku'] ?? null,
                'barcode' => $variantPayload['barcode'] ?? null,
                'title' => $variantPayload['title'] ?? null,
                'price' => $variantPayload['price'] ?? 0,
                'compare_at_price' => $variantPayload['compareAtPrice'] ?? null,
                'weight' => $weight['value'],
                'weight_unit' => $weight['unit'],
                'option1' => $options['option1_value'],
                'option1_name' => $options['option1_name'],
                'option1_value' => $options['option1_value'],
                'option2' => $options['option2_value'],
                'option2_name' => $options['option2_name'],
                'option2_value' => $options['option2_value'],
                'option3' => $options['option3_value'],
                'option3_name' => $options['option3_name'],
                'option3_value' => $options['option3_value'],
                'raw_payload' => $variantPayload,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $variantPayload
     */
    private function syncInventory(ProductVariant $variant, array $variantPayload): void
    {
        $quantity = $this->sumAvailableInventory($variantPayload);

        InventoryLevel::query()->updateOrCreate(
            [
                'variant_id' => $variant->id,
                'warehouse_name' => self::WAREHOUSE_NAME,
            ],
            [
                'quantity' => $quantity,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $productPayload
     */
    private function syncImages(Product $product, array $productPayload): void
    {
        $mediaNodes = $productPayload['media']['nodes'] ?? [];

        if (! is_array($mediaNodes)) {
            return;
        }

        foreach ($mediaNodes as $index => $mediaPayload) {
            if (! is_array($mediaPayload)) {
                continue;
            }

            $url = data_get($mediaPayload, 'image.url');

            if (blank($url)) {
                continue;
            }

            ProductImage::query()->updateOrCreate(
                [
                    'product_id' => $product->id,
                    'url' => (string) $url,
                ],
                [
                    'position' => $index,
                    'alt' => $mediaPayload['alt'] ?? data_get($mediaPayload, 'image.altText'),
                ],
            );

            $this->imagesImported++;
        }
    }

    /**
     * @param  array<string, mixed>  $variantPayload
     */
    private function sumAvailableInventory(array $variantPayload): int
    {
        $inventoryItem = $variantPayload['inventoryItem'] ?? null;

        if (! is_array($inventoryItem)) {
            return 0;
        }

        $levels = $inventoryItem['inventoryLevels']['nodes'] ?? [];

        if (! is_array($levels)) {
            return 0;
        }

        $total = 0;

        foreach ($levels as $level) {
            if (! is_array($level)) {
                continue;
            }

            $quantities = $level['quantities'] ?? [];

            if (! is_array($quantities)) {
                continue;
            }

            foreach ($quantities as $quantityRow) {
                if (! is_array($quantityRow)) {
                    continue;
                }

                if (($quantityRow['name'] ?? null) === 'available') {
                    $total += (int) ($quantityRow['quantity'] ?? 0);
                }
            }
        }

        return $total;
    }

    /**
     * @param  array<int, array<string, mixed>>  $selectedOptions
     * @return array{
     *     option1_name: ?string,
     *     option1_value: ?string,
     *     option2_name: ?string,
     *     option2_value: ?string,
     *     option3_name: ?string,
     *     option3_value: ?string,
     * }
     */
    private function mapOptionFields(array $selectedOptions): array
    {
        $fields = [
            'option1_name' => null,
            'option1_value' => null,
            'option2_name' => null,
            'option2_value' => null,
            'option3_name' => null,
            'option3_value' => null,
        ];

        $pairs = collect($selectedOptions)
            ->filter(fn ($option) => is_array($option))
            ->map(fn (array $option): array => [
                'name' => (string) ($option['name'] ?? ''),
                'value' => (string) ($option['value'] ?? ''),
            ])
            ->filter(fn (array $option): bool => filled($option['name']) && filled($option['value']))
            ->values();

        foreach ($pairs as $index => $pair) {
            if ($index > 2) {
                break;
            }

            $slot = $index + 1;
            $fields["option{$slot}_name"] = $pair['name'];
            $fields["option{$slot}_value"] = $pair['value'];
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $variantPayload
     * @return array{value: ?float, unit: ?string}
     */
    private function extractWeight(array $variantPayload): array
    {
        $weight = data_get($variantPayload, 'inventoryItem.measurement.weight');

        if (! is_array($weight)) {
            return ['value' => null, 'unit' => null];
        }

        return [
            'value' => isset($weight['value']) ? (float) $weight['value'] : null,
            'unit' => isset($weight['unit']) ? (string) $weight['unit'] : null,
        ];
    }

    private function mapProductStatus(string $shopifyStatus): ProductStatus
    {
        return match (strtoupper($shopifyStatus)) {
            'DRAFT' => ProductStatus::Draft,
            'ARCHIVED' => ProductStatus::Archived,
            default => ProductStatus::Active,
        };
    }

    private function normalizeProductGid(string $shopifyProductGid): string
    {
        if (str_starts_with($shopifyProductGid, 'gid://')) {
            return $shopifyProductGid;
        }

        return 'gid://shopify/Product/'.$shopifyProductGid;
    }

    private function extractIdFromGid(string $gid): string
    {
        return Str::afterLast($gid, '/');
    }

    private function resolveSource(): Source
    {
        return Source::query()->firstOrCreate(
            [
                'type' => 'shopify',
                'name' => 'Shopify',
            ],
            [
                'enabled' => true,
                'config' => [
                    'shop' => config('shopify.shop'),
                ],
            ],
        );
    }

    private function startSyncJob(): SyncJob
    {
        return SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Running,
            'started_at' => now(),
            'context' => [
                'shop' => config('shopify.shop'),
            ],
        ]);
    }

    private function finishSyncJob(SyncJob $syncJob): void
    {
        $status = match (true) {
            $this->failedItems > 0 && $syncJob->success_items > 0 => SyncJobStatus::Partial,
            $this->failedItems > 0 => SyncJobStatus::Failed,
            default => SyncJobStatus::Completed,
        };

        $syncJob->update([
            'status' => $status,
            'finished_at' => now(),
            'failed_items' => $this->failedItems,
            'context' => array_merge($syncJob->context ?? [], [
                'products_imported' => $this->productsImported,
                'variants_imported' => $this->variantsImported,
                'images_imported' => $this->imagesImported,
                'failed_items' => $this->failedItems,
                'new_products_count' => $this->newProductsCount,
                'updated_products_count' => $this->updatedProductsCount,
                'pending_review_products_count' => $this->pendingReviewProductsCount,
                'unpublished_products_count' => $this->unpublishedProductsCount,
            ]),
        ]);
    }

    private function failSyncJob(SyncJob $syncJob, Throwable $exception): void
    {
        $syncJob->update([
            'status' => SyncJobStatus::Failed,
            'finished_at' => now(),
            'failed_items' => $this->failedItems,
            'error_message' => $exception->getMessage(),
            'context' => array_merge($syncJob->context ?? [], [
                'products_imported' => $this->productsImported,
                'variants_imported' => $this->variantsImported,
                'images_imported' => $this->imagesImported,
                'failed_items' => $this->failedItems,
                'new_products_count' => $this->newProductsCount,
                'updated_products_count' => $this->updatedProductsCount,
                'pending_review_products_count' => $this->pendingReviewProductsCount,
                'unpublished_products_count' => $this->unpublishedProductsCount,
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function recordProductFailure(
        SyncJob $syncJob,
        ?string $externalId,
        ?string $sku,
        string $message,
        ?array $payload,
    ): void {
        $productId = null;

        if ($externalId !== null) {
            $productId = Product::query()
                ->where('external_id', $externalId)
                ->value('id');
        }

        SyncJobItem::query()->create([
            'sync_job_id' => $syncJob->id,
            'product_id' => $productId,
            'sku' => $sku,
            'status' => SyncJobItemStatus::Failed,
            'message' => $message,
            'payload' => $payload,
        ]);

        $syncJob->increment('failed_items');
        $this->failedItems++;
    }

    /**
     * @param  array<string, mixed>  $productPayload
     */
    private function firstSkuFromPayload(array $productPayload): ?string
    {
        $variants = $productPayload['variants']['nodes'] ?? [];

        if (! is_array($variants)) {
            return null;
        }

        foreach ($variants as $variant) {
            if (is_array($variant) && filled($variant['sku'] ?? null)) {
                return (string) $variant['sku'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $productPayload
     */
    private function syncSourceCategories(Source $source, Product $product, array $productPayload): void
    {
        $categoryIds = [];

        $collectionNodes = $productPayload['collections']['nodes'] ?? [];

        if (is_array($collectionNodes)) {
            foreach ($collectionNodes as $collectionPayload) {
                if (! is_array($collectionPayload)) {
                    continue;
                }

                $externalId = $this->extractIdFromGid((string) ($collectionPayload['id'] ?? ''));

                if ($externalId === '') {
                    continue;
                }

                $category = SourceCategory::query()->updateOrCreate(
                    [
                        'source_id' => $source->id,
                        'type' => 'collection',
                        'external_id' => $externalId,
                    ],
                    [
                        'name' => (string) ($collectionPayload['title'] ?? 'Collection'),
                        'handle' => $collectionPayload['handle'] ?? null,
                        'raw_payload' => $collectionPayload,
                    ],
                );

                $categoryIds[] = $category->id;
            }
        }

        $productType = $productPayload['productType'] ?? null;

        if (filled($productType)) {
            $category = SourceCategory::query()->updateOrCreate(
                [
                    'source_id' => $source->id,
                    'type' => 'product_type',
                    'name' => (string) $productType,
                ],
                [
                    'external_id' => null,
                    'handle' => null,
                    'raw_payload' => ['value' => $productType],
                ],
            );

            $categoryIds[] = $category->id;
        }

        $tags = $productPayload['tags'] ?? [];

        if (is_array($tags)) {
            foreach ($tags as $tag) {
                if (blank($tag)) {
                    continue;
                }

                $category = SourceCategory::query()->updateOrCreate(
                    [
                        'source_id' => $source->id,
                        'type' => 'tag',
                        'name' => (string) $tag,
                    ],
                    [
                        'external_id' => null,
                        'handle' => null,
                        'raw_payload' => ['value' => $tag],
                    ],
                );

                $categoryIds[] = $category->id;
            }
        }

        $product->sourceCategories()->sync($categoryIds);
    }

    private function resetCounters(): void
    {
        $this->productsImported = 0;
        $this->variantsImported = 0;
        $this->imagesImported = 0;
        $this->failedItems = 0;
        $this->newProductsCount = 0;
        $this->updatedProductsCount = 0;
        $this->pendingReviewProductsCount = 0;
        $this->unpublishedProductsCount = 0;
    }

    private function productsQuery(): string
    {
        $productPageSize = (int) config('shopify.product_page_size', 20);
        $mediaPageSize = (int) config('shopify.media_page_size', 5);
        $collectionPageSize = (int) config('shopify.collection_page_size', 20);

        return <<<GRAPHQL
        query ImportActiveProducts(\$cursor: String) {
          products(first: {$productPageSize}, after: \$cursor, query: "status:active") {
            pageInfo {
              hasNextPage
              endCursor
            }
            nodes {
              id
              title
              descriptionHtml
              vendor
              productType
              handle
              status
              tags
              totalVariants
              options {
                name
                values
              }
              collections(first: {$collectionPageSize}) {
                nodes {
                  id
                  title
                  handle
                }
              }
              media(first: {$mediaPageSize}) {
                nodes {
                  ... on MediaImage {
                    id
                    alt
                    image {
                      url
                      altText
                    }
                  }
                }
              }
            }
          }
        }
        GRAPHQL;
    }

    private function productVariantsQuery(): string
    {
        $inventoryLevelPageSize = (int) config('shopify.inventory_level_page_size', 1);

        return <<<GRAPHQL
        query ProductVariants(\$productId: ID!, \$cursor: String, \$first: Int!) {
          product(id: \$productId) {
            variants(first: \$first, after: \$cursor) {
              pageInfo {
                hasNextPage
                endCursor
              }
              nodes {
                id
                title
                sku
                barcode
                price
                compareAtPrice
                selectedOptions {
                  name
                  value
                }
                inventoryItem {
                  id
                  sku
                  tracked
                  measurement {
                    weight {
                      value
                      unit
                    }
                  }
                  inventoryLevels(first: {$inventoryLevelPageSize}) {
                    nodes {
                      quantities(names: ["available"]) {
                        name
                        quantity
                      }
                    }
                  }
                }
              }
            }
          }
        }
        GRAPHQL;
    }
}
