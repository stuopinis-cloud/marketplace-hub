<?php

namespace App\Services\Shopify;

use App\Enums\ProductStatus;
use App\Enums\SyncJobItemStatus;
use App\Enums\SyncJobStatus;
use App\Enums\VarleExportStatus;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Exceptions\Shopify\ShopifyImportCancelledException;
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

    private ShopifyImportOptions $options;

    private int $currentProductIndex = 0;

    private int $variantsForCurrentProduct = 0;

    public function __construct(
        private readonly ShopifyClient $client,
    ) {
        $this->options = new ShopifyImportOptions;
    }

    public function import(?ShopifyImportOptions $options = null): ShopifyImportResult
    {
        $this->options = $options ?? new ShopifyImportOptions;
        $this->resetCounters();

        $source = $this->resolveSource();
        $syncJob = $this->startSyncJob();
        $finalized = false;

        try {
            foreach ($this->fetchProductPages() as $products) {
                $this->assertNotCancelled($syncJob);

                foreach ($products as $productPayload) {
                    $this->assertNotCancelled($syncJob);

                    if ($this->hasReachedImportLimit()) {
                        break 2;
                    }

                    $this->currentProductIndex++;
                    $this->variantsForCurrentProduct = 0;
                    $this->updateSyncJobProgress($syncJob, $productPayload, 'starting');
                    $this->importProduct($source, $syncJob, $productPayload);
                    $this->updateSyncJobProgress($syncJob, $productPayload, 'done');
                    $this->emitProgress($syncJob, $productPayload, 'done');
                }
            }

            $this->finishSyncJob($syncJob);
            $finalized = true;

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
        } catch (ShopifyImportCancelledException $exception) {
            $this->cancelSyncJob($syncJob, $exception->getMessage());
            $finalized = true;

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
            $finalized = true;

            throw $exception;
        } finally {
            if (! $finalized && $syncJob->fresh()?->status === SyncJobStatus::Running) {
                $this->failSyncJob(
                    $syncJob,
                    new \RuntimeException('Import process exited while sync job was still running.'),
                );
            }
        }
    }

    /**
     * @return \Generator<int, array<int, array<string, mixed>>>
     */
    private function fetchProductPages(): \Generator
    {
        if (filled($this->options->handle)) {
            yield $this->fetchProductsByHandle((string) $this->options->handle);

            return;
        }

        $cursor = null;

        do {
            $this->assertNotCancelled($this->runningSyncJob());

            try {
                $response = $this->client->query(
                    $this->productsQuery(),
                    [
                        'cursor' => $cursor,
                        'query' => 'status:active',
                    ],
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
                if ($this->options->limit !== null) {
                    $remaining = $this->options->limit - $this->currentProductIndex;
                    $nodes = array_slice($nodes, 0, max(0, $remaining));
                }

                if ($nodes !== []) {
                    yield $nodes;
                }
            }

            if ($this->hasReachedImportLimit()) {
                break;
            }

            $pageInfo = $connection['pageInfo'] ?? [];
            $hasNextPage = (bool) ($pageInfo['hasNextPage'] ?? false);
            $cursor = $hasNextPage ? ($pageInfo['endCursor'] ?? null) : null;
        } while ($cursor !== null);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchProductsByHandle(string $handle): array
    {
        try {
            $response = $this->client->query(
                $this->productsQuery(),
                [
                    'cursor' => null,
                    'query' => 'handle:'.$handle,
                ],
            );
        } catch (ShopifyGraphQlException $exception) {
            throw $exception->withQueryCostGuidance();
        }

        $nodes = data_get($response, 'data.products.nodes', []);

        return is_array($nodes) ? $nodes : [];
    }

    private ?SyncJob $activeSyncJob = null;

    private function runningSyncJob(): SyncJob
    {
        if ($this->activeSyncJob === null) {
            throw new \RuntimeException('Sync job has not been started.');
        }

        return $this->activeSyncJob;
    }

    private function assertNotCancelled(SyncJob $syncJob): void
    {
        $syncJob->refresh();

        if ($syncJob->cancel_requested_at !== null) {
            throw new ShopifyImportCancelledException('Shopify import cancellation was requested.');
        }
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
            $this->updateSyncJobProgress($syncJob, $productPayload, 'variants');

            $importResult = DB::transaction(function () use ($source, $productPayload, $externalId, $syncJob): array {
                $result = $this->upsertProduct($source, $productPayload, $externalId);
                $product = $result['product'];
                $this->syncVariants($product, (string) ($productPayload['id'] ?? ''), $syncJob, $productPayload);
                $this->updateSyncJobProgress($syncJob, $productPayload, 'images');
                $this->syncImages($product, $productPayload);
                $this->updateSyncJobProgress($syncJob, $productPayload, 'categories');
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
            $this->assertNotCancelled($this->runningSyncJob());

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

    /**
     * @param  array<string, mixed>  $productPayload
     */
    private function syncVariants(
        Product $product,
        string $shopifyProductGid,
        SyncJob $syncJob,
        array $productPayload,
    ): void {
        if ($shopifyProductGid === '') {
            return;
        }

        foreach ($this->fetchAllVariantsForProduct($shopifyProductGid) as $variantPayload) {
            $variant = $this->upsertVariant($product, $variantPayload);
            $this->syncInventory($variant, $variantPayload);
            $this->variantsImported++;
            $this->variantsForCurrentProduct++;
            $this->updateSyncJobProgress($syncJob, $productPayload, 'variants');
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
        $this->activeSyncJob = SyncJob::query()->create([
            'type' => 'import',
            'source' => 'shopify',
            'status' => SyncJobStatus::Running,
            'started_at' => now(),
            'heartbeat_at' => now(),
            'process_id' => getmypid() ?: null,
            'context' => [
                'shop' => config('shopify.shop'),
                'limit' => $this->options->limit,
                'handle' => $this->options->handle,
                'stage' => 'starting',
            ],
        ]);

        return $this->activeSyncJob;
    }

    /**
     * @param  array<string, mixed>  $productPayload
     */
    private function updateSyncJobProgress(SyncJob $syncJob, array $productPayload, string $stage): void
    {
        $attempted = $this->productsImported + $this->failedItems + ($stage === 'starting' ? 0 : 0);

        $syncJob->update([
            'total_items' => max($syncJob->total_items, $this->currentProductIndex),
            'success_items' => $this->productsImported,
            'failed_items' => $this->failedItems,
            'heartbeat_at' => now(),
            'process_id' => getmypid() ?: $syncJob->process_id,
            'context' => array_merge($syncJob->context ?? [], [
                'current_product_handle' => (string) ($productPayload['handle'] ?? ''),
                'current_product_index' => $this->currentProductIndex,
                'stage' => $stage,
                'last_progress_at' => now()->toIso8601String(),
                'variants_for_current_product' => $this->variantsForCurrentProduct,
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $productPayload
     */
    private function emitProgress(SyncJob $syncJob, array $productPayload, string $stage): void
    {
        if (! $this->options->verbose || $this->options->progressCallback === null) {
            return;
        }

        ($this->options->progressCallback)(
            $this->currentProductIndex,
            $this->progressTotalLabel(),
            (string) ($productPayload['handle'] ?? 'unknown'),
            $this->variantsForCurrentProduct,
            $stage,
        );
    }

    private function progressTotalLabel(): string
    {
        if ($this->options->limit !== null) {
            return (string) $this->options->limit;
        }

        if (filled($this->options->handle)) {
            return '1';
        }

        return '?';
    }

    private function hasReachedImportLimit(): bool
    {
        return $this->options->limit !== null
            && $this->currentProductIndex >= $this->options->limit;
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
            'success_items' => $this->productsImported,
            'total_items' => max($syncJob->total_items, $this->currentProductIndex),
            'heartbeat_at' => now(),
            'context' => array_merge($syncJob->context ?? [], [
                'products_imported' => $this->productsImported,
                'variants_imported' => $this->variantsImported,
                'images_imported' => $this->imagesImported,
                'failed_items' => $this->failedItems,
                'new_products_count' => $this->newProductsCount,
                'updated_products_count' => $this->updatedProductsCount,
                'pending_review_products_count' => $this->pendingReviewProductsCount,
                'unpublished_products_count' => $this->unpublishedProductsCount,
                'stage' => 'finished',
                'last_progress_at' => now()->toIso8601String(),
            ]),
        ]);
    }

    private function cancelSyncJob(SyncJob $syncJob, string $message): void
    {
        $syncJob->update([
            'status' => SyncJobStatus::Cancelled,
            'cancelled_at' => now(),
            'finished_at' => now(),
            'failed_items' => $this->failedItems,
            'success_items' => $this->productsImported,
            'total_items' => max($syncJob->total_items, $this->currentProductIndex),
            'error_message' => $message,
            'heartbeat_at' => now(),
            'context' => array_merge($syncJob->context ?? [], [
                'products_imported' => $this->productsImported,
                'variants_imported' => $this->variantsImported,
                'images_imported' => $this->imagesImported,
                'failed_items' => $this->failedItems,
                'new_products_count' => $this->newProductsCount,
                'updated_products_count' => $this->updatedProductsCount,
                'pending_review_products_count' => $this->pendingReviewProductsCount,
                'unpublished_products_count' => $this->unpublishedProductsCount,
                'exception_message' => $message,
                'stage' => 'cancelled',
                'last_progress_at' => now()->toIso8601String(),
            ]),
        ]);
    }

    private function failSyncJob(SyncJob $syncJob, Throwable $exception): void
    {
        $syncJob->update([
            'status' => SyncJobStatus::Failed,
            'finished_at' => now(),
            'failed_items' => $this->failedItems,
            'success_items' => $this->productsImported,
            'total_items' => max($syncJob->total_items, $this->currentProductIndex),
            'error_message' => $exception->getMessage(),
            'heartbeat_at' => now(),
            'context' => array_merge($syncJob->context ?? [], [
                'products_imported' => $this->productsImported,
                'variants_imported' => $this->variantsImported,
                'images_imported' => $this->imagesImported,
                'failed_items' => $this->failedItems,
                'new_products_count' => $this->newProductsCount,
                'updated_products_count' => $this->updatedProductsCount,
                'pending_review_products_count' => $this->pendingReviewProductsCount,
                'unpublished_products_count' => $this->unpublishedProductsCount,
                'exception_message' => $exception->getMessage(),
                'exception_class' => $exception::class,
                'stage' => 'failed',
                'last_progress_at' => now()->toIso8601String(),
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
        $this->updateSyncJobProgress($syncJob, $payload ?? [], 'failed');
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
        $this->currentProductIndex = 0;
        $this->variantsForCurrentProduct = 0;
        $this->activeSyncJob = null;
    }

    private function productsQuery(): string
    {
        $productPageSize = (int) config('shopify.product_page_size', 20);
        $mediaPageSize = (int) config('shopify.media_page_size', 5);
        $collectionPageSize = (int) config('shopify.collection_page_size', 20);

        return <<<GRAPHQL
        query ImportActiveProducts(\$cursor: String, \$query: String) {
          products(first: {$productPageSize}, after: \$cursor, query: \$query) {
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
