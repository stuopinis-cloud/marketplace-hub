<?php

namespace App\Services\Marketplace\Varle;

use App\Enums\FeedFileStatus;
use App\Enums\SyncJobItemStatus;
use App\Enums\SyncJobStatus;
use App\Models\FeedFile;
use App\Models\MarketplaceChannel;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SyncJob;
use App\Models\SyncJobItem;
use App\Services\Marketplace\CategoryResolver;
use DOMDocument;
use DOMElement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class VarleXmlExporter
{
    private int $exportedProducts = 0;

    private int $exportedVariants = 0;

    private int $skippedVariants = 0;

    private bool $debug = false;

    private int $debugProductCount = 0;

    /** @var array<int, string> */
    private array $warnings = [];

    /** @var array<int, string> */
    private array $debugLines = [];

    /** @var array<string, int> */
    private array $previewSkipReasons = [];

    private int $previewPendingReviewProducts = 0;

    private int $previewExcludedProducts = 0;

    private int $previewCategoryDisabledProducts = 0;

    private int $previewUnpublishedProducts = 0;

    private int $previewMissingBarcodeVariants = 0;

    private int $previewMissingCategoryProducts = 0;

    private int $previewFallbackCategoryProducts = 0;

    private int $previewExportableProducts = 0;

    private int $previewExportableVariants = 0;

    private int $previewSkippedVariants = 0;

    public function __construct(
        private readonly VarleProductValidator $validator,
        private readonly CategoryResolver $categoryResolver,
        private readonly VarleExportGatekeeper $exportGatekeeper,
    ) {}

    public function export(bool $debug = false): VarleExportResult
    {
        $this->debug = $debug;
        $this->resetCounters();

        $channel = $this->resolveChannel();
        $config = $this->channelConfig($channel);
        $syncJob = $this->startSyncJob($channel);

        try {
            $relativePath = $this->feedRelativePath($config);
            $feedPath = $this->writeFeedFile($relativePath, $config, $syncJob, $channel);
            $publicUrl = url('/feeds/varle.xml');

            $this->upsertFeedFile($channel, $config, $relativePath, $publicUrl);
            $this->finishSyncJob($syncJob, $relativePath, $publicUrl);

            return new VarleExportResult(
                syncJobId: $syncJob->id,
                exportedVariants: $this->exportedVariants,
                skippedVariants: $this->skippedVariants,
                feedPath: $feedPath,
                publicUrl: $publicUrl,
                debugLines: $this->debugLines,
            );
        } catch (Throwable $exception) {
            $this->failSyncJob($syncJob, $exception);

            throw $exception;
        }
    }

    public function preview(): VarleExportPreviewResult
    {
        $channel = $this->resolveChannel();
        $config = $this->channelConfig($channel);
        $this->resetPreviewState();

        $this->chunkProducts($this->exportChunkSize(), function (Collection $products) use ($channel, $config): void {
            foreach ($products as $product) {
                $this->previewProduct($product, $channel, $config);
            }
        });

        return $this->buildPreviewResult();
    }

    /**
     * @param  callable(Collection<int, Product>): void  $callback
     */
    protected function chunkProducts(int $chunkSize, callable $callback): void
    {
        $this->productsQuery()->chunkById($chunkSize, $callback);
    }

    protected function productsQuery(): Builder
    {
        return Product::query()
            ->with([
                'images',
                'variants.inventoryLevels',
                'sourceCategories',
            ])
            ->orderBy('id');
    }

    protected function exportChunkSize(): int
    {
        return (int) config('marketplace.exports.varle.export_chunk_size', 100);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function writeFeedFile(
        string $relativePath,
        array $config,
        SyncJob $syncJob,
        MarketplaceChannel $channel,
    ): string {
        Storage::disk('public')->makeDirectory('feeds');

        $absolutePath = Storage::disk('public')->path($relativePath);
        $handle = fopen($absolutePath, 'w');

        if ($handle === false) {
            throw new \RuntimeException('Unable to open Varle feed file for writing.');
        }

        try {
            fwrite($handle, '<?xml version="1.0" encoding="UTF-8"?>'.PHP_EOL);
            fwrite($handle, '<products>'.PHP_EOL);

            $this->chunkProducts($this->exportChunkSize(), function (Collection $products) use (
                $handle,
                $syncJob,
                $config,
                $channel,
            ): void {
                foreach ($products as $product) {
                    $this->processProduct($product, $handle, $syncJob, $config, $channel);
                }
            });

            fwrite($handle, '</products>'.PHP_EOL);
        } finally {
            fclose($handle);
        }

        return $absolutePath;
    }

    /**
     * @param  resource  $handle
     * @param  array<string, mixed>  $config
     */
    private function processProduct(
        Product $product,
        $handle,
        SyncJob $syncJob,
        array $config,
        MarketplaceChannel $channel,
    ): void {
        $variants = $product->variants;

        foreach ($variants as $variant) {
            $syncJob->increment('total_items');
        }

        $gate = $this->exportGatekeeper->assess($product, $channel);

        if (! $gate->allowed) {
            $this->recordSkippedProduct(
                $syncJob,
                $product,
                (string) $gate->skipMessage,
                $variants->count(),
                payload: $this->exportContextPayload($product, $gate),
            );

            return;
        }

        $categoryExplanation = $gate->categoryExplanation ?? $this->categoryResolver->explain($product, $channel);
        $productValidation = $this->validator->validateProduct(
            $product,
            $channel,
            $config,
            $categoryExplanation,
        );

        if (! $productValidation->isValid) {
            $this->recordSkippedProduct(
                $syncJob,
                $product,
                $productValidation->message(),
                $variants->count(),
                $productValidation->errors,
                $this->exportContextPayload($product, $gate),
            );

            return;
        }

        foreach ($productValidation->warnings as $warning) {
            $this->warnings[] = sprintf('Product %s: %s', $product->handle, $warning);
        }

        if ($categoryExplanation['fallback_used'] && filled($categoryExplanation['resolved_category'])) {
            $this->warnings[] = sprintf(
                'Product %s: No category mapping found, using fallback category: %s',
                $product->handle,
                $categoryExplanation['resolved_category'],
            );
        }

        $allValidVariants = $this->collectValidVariants($product, $variants->all(), $syncJob, $config);
        $hasColor = $this->hasColorAmongVariants($allValidVariants);
        $exportGroups = $this->buildExportGroups($allValidVariants, $hasColor);
        $exportedGroupCount = 0;
        $generatedIds = [];

        foreach ($exportGroups as $exportGroup) {
            $groupValidVariants = $exportGroup['variants'];

            if ($groupValidVariants === []) {
                if (filled($exportGroup['color_value'])) {
                    $this->recordSkippedColorGroup($syncJob, $product, (string) $exportGroup['color_value']);
                }

                continue;
            }

            $imageResolution = VarleVariantPresenter::resolveExportImageUrls(
                $product,
                $groupValidVariants,
                $config,
            );

            if ($imageResolution['urls'] === []) {
                $this->recordSkippedExportGroupForImages(
                    $syncJob,
                    $product,
                    $groupValidVariants,
                    $config,
                    $imageResolution,
                    $exportGroup['color_value'],
                );

                continue;
            }

            $payload = $this->buildProductPayload(
                $product,
                $groupValidVariants,
                $config,
                $categoryExplanation,
                $exportGroup['color_value'],
                $imageResolution,
            );

            fwrite($handle, $this->renderProductXml($payload).PHP_EOL);
            $generatedIds[] = (string) $payload['id'];

            foreach ($groupValidVariants as $validVariant) {
                $syncJob->increment('success_items');
                $this->exportedVariants++;
            }

            $exportedGroupCount++;
            $this->exportedProducts++;
        }

        if ($this->debug && $this->debugProductCount < 10 && $variants->isNotEmpty()) {
            $this->recordDebugProduct($product, $allValidVariants, $hasColor, $generatedIds);
        }

        if ($exportedGroupCount === 0 && $variants->isNotEmpty()) {
            $this->recordSkippedProduct(
                $syncJob,
                $product,
                'Product skipped because all variants are invalid or missing barcode',
                0,
            );
        }
    }

    /**
     * @param  array<int, ProductVariant>  $variants
     * @param  array<string, mixed>  $config
     * @return array<int, array{variant: ProductVariant, quantity: int}>
     */
    private function collectValidVariants(
        Product $product,
        array $variants,
        SyncJob $syncJob,
        array $config,
    ): array {
        $validVariants = [];

        foreach ($variants as $variant) {
            if (blank($variant->barcode)) {
                $this->recordSkippedVariant($syncJob, $variant, 'Missing barcode');

                continue;
            }

            $variantValidation = $this->validator->validateVariant($variant, $config);

            if (! $variantValidation->isValid) {
                $this->recordSkippedVariant(
                    $syncJob,
                    $variant,
                    $variantValidation->message(),
                    $variantValidation->errors,
                );

                continue;
            }

            $validVariants[] = [
                'variant' => $variant,
                'quantity' => $this->sumInventoryQuantity($variant),
            ];
        }

        return $validVariants;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<int, array{variant: ProductVariant, quantity: int}>  $validVariants
     * @param  array{
     *     resolved_category: ?string,
     *     source: ?string,
     *     matched_mapping_id: ?int,
     *     matched_source_type: ?string,
     *     matched_source_value: ?string,
     *     fallback_used: bool,
     *     details: array<string, mixed>
     * }  $categoryExplanation
     * @return array<string, mixed>
     */
    private function buildProductPayload(
        Product $product,
        array $validVariants,
        array $config,
        array $categoryExplanation,
        ?string $colorValue,
        ?array $imageResolution = null,
    ): array {
        $category = (string) $categoryExplanation['resolved_category'];
        $multiplier = (float) ($config['price_multiplier'] ?? 1);
        $outputVariants = $this->shouldOutputVariants($validVariants);
        $firstVariant = $validVariants[0]['variant'];

        $adjustedPrices = collect($validVariants)->map(
            fn (array $row): float => (float) $row['variant']->price * $multiplier,
        );

        $basePrice = (float) $adjustedPrices->min();
        $priceOld = $this->resolvePriceOld($validVariants, $basePrice, $multiplier, $outputVariants);

        $productId = (string) $product->handle;

        if (filled($colorValue)) {
            $productId .= '-'.Str::slug($colorValue);
        }

        $title = (string) $product->title;

        if (filled($colorValue)) {
            $title .= ', '.$colorValue;
        }

        $imageResolution ??= VarleVariantPresenter::resolveExportImageUrls($product, $validVariants, $config);

        $payload = [
            'url' => $this->resolveProductUrl($product, $config),
            'id' => $productId,
            'model' => (string) $firstVariant->sku,
            'category' => $category,
            'title' => $title,
            'description' => (string) $product->description_html,
            'price' => $this->formatPrice($basePrice),
            'prime_costs' => $this->formatPrice($this->calculatePrimeCosts($basePrice, $config)),
            'manufacturer' => $this->resolveManufacturer($product),
            'images' => $imageResolution['urls'],
            'barcode_format' => 'EAN',
            'group' => (string) $product->handle,
            'is_multi_variant' => $outputVariants,
            'weight' => $this->weightInKg($firstVariant),
        ];

        if ($priceOld !== null) {
            $payload['price_old'] = $this->formatPrice($priceOld);
        }

        if ($outputVariants) {
            $groupTitle = $this->nonColorGroupTitle($validVariants);
            $payload['variants'] = collect($validVariants)->map(function (array $row) use (
                $groupTitle,
                $basePrice,
                $multiplier,
            ): array {
                $variant = $row['variant'];
                $variantPrice = (float) $variant->price * $multiplier;

                return [
                    'group_title' => $groupTitle,
                    'title' => $this->variantDisplayTitle($variant),
                    'quantity' => $row['quantity'],
                    'barcode' => (string) $variant->barcode,
                    'price' => $this->formatPrice($variantPrice - $basePrice),
                ];
            })->all();
        } else {
            $payload['quantity'] = $validVariants[0]['quantity'];
            $payload['barcode'] = (string) $firstVariant->barcode;
        }

        return $payload;
    }

    /**
     * @param  array<int, array{variant: ProductVariant, quantity: int}>  $validVariants
     */
    private function resolvePriceOld(
        array $validVariants,
        float $basePrice,
        float $multiplier,
        bool $outputVariants,
    ): ?float {
        $comparePrices = collect($validVariants)
            ->map(function (array $row) use ($multiplier): ?float {
                $compareAtPrice = $row['variant']->compare_at_price;

                if (blank($compareAtPrice)) {
                    return null;
                }

                return (float) $compareAtPrice * $multiplier;
            })
            ->filter(fn (?float $price) => $price !== null && $price > $basePrice);

        if ($comparePrices->isEmpty()) {
            return null;
        }

        if ($outputVariants) {
            return (float) $comparePrices->min();
        }

        $variantPrice = (float) $validVariants[0]['variant']->price * $multiplier;
        $compareAtPrice = (float) $comparePrices->first();

        return $compareAtPrice > $variantPrice ? $compareAtPrice : null;
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function renderProductXml(array $product): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $productElement = $document->createElement('product');
        $document->appendChild($productElement);

        if (filled($product['url'] ?? null)) {
            $this->appendCdataElement($document, $productElement, 'url', (string) $product['url']);
        }

        $this->appendTextElement($document, $productElement, 'id', (string) $product['id']);
        $this->appendCdataElement($document, $productElement, 'model', (string) $product['model']);

        $categoriesElement = $document->createElement('categories');
        $productElement->appendChild($categoriesElement);
        $this->appendCdataElement($document, $categoriesElement, 'category', (string) $product['category']);

        $this->appendCdataElement($document, $productElement, 'title', (string) $product['title']);
        $this->appendCdataElement($document, $productElement, 'description', (string) $product['description']);
        $this->appendTextElement($document, $productElement, 'price', (string) $product['price']);

        if (filled($product['price_old'] ?? null)) {
            $this->appendTextElement($document, $productElement, 'price_old', (string) $product['price_old']);
        }

        $this->appendTextElement($document, $productElement, 'prime_costs', (string) $product['prime_costs']);

        if (filled($product['weight'] ?? null)) {
            $this->appendTextElement($document, $productElement, 'weight', (string) $product['weight']);
        }

        if (filled($product['manufacturer'] ?? null)) {
            $this->appendCdataElement($document, $productElement, 'manufacturer', (string) $product['manufacturer']);
        }

        $imagesElement = $document->createElement('images');
        $productElement->appendChild($imagesElement);

        foreach ($product['images'] as $imageUrl) {
            $this->appendCdataElement($document, $imagesElement, 'image', (string) $imageUrl);
        }

        if (! ($product['is_multi_variant'] ?? false)) {
            $this->appendTextElement($document, $productElement, 'quantity', (string) $product['quantity']);
        }

        $this->appendTextElement($document, $productElement, 'barcode_format', (string) $product['barcode_format']);

        if (! ($product['is_multi_variant'] ?? false)) {
            $this->appendTextElement($document, $productElement, 'barcode', (string) $product['barcode']);
        }

        $this->appendCdataElement($document, $productElement, 'group', (string) $product['group']);

        if ($product['is_multi_variant'] ?? false) {
            $variantsElement = $document->createElement('variants');
            $productElement->appendChild($variantsElement);

            foreach ($product['variants'] as $variantPayload) {
                $variantElement = $document->createElement('variant');
                $variantElement->setAttribute('group_title', (string) $variantPayload['group_title']);
                $variantsElement->appendChild($variantElement);

                $this->appendTextElement($document, $variantElement, 'title', (string) $variantPayload['title']);
                $this->appendTextElement($document, $variantElement, 'quantity', (string) $variantPayload['quantity']);
                $this->appendTextElement($document, $variantElement, 'barcode', (string) $variantPayload['barcode']);
                $this->appendTextElement($document, $variantElement, 'price', (string) $variantPayload['price']);
            }
        }

        return (string) $document->saveXML($productElement);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function resolveProductUrl(Product $product, array $config): ?string
    {
        if (blank($product->handle)) {
            return null;
        }

        $storeUrl = $config['store_url'] ?? config('marketplace.exports.varle.store_url');

        if (blank($storeUrl)) {
            return null;
        }

        return rtrim((string) $storeUrl, '/').'/products/'.$product->handle;
    }

    private function resolveChannel(): MarketplaceChannel
    {
        return MarketplaceChannel::query()->firstOrCreate(
            [
                'type' => 'varle',
                'name' => 'Varle.lt',
            ],
            [
                'enabled' => true,
                'config' => [
                    'default_category' => 'Kita',
                    'export_zero_stock' => true,
                    'price_multiplier' => 1,
                    'feed_filename' => 'varle.xml',
                    'require_category_mapping' => false,
                    'allow_fallback_product_images' => false,
                    'vat_rate' => 21,
                ],
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function channelConfig(MarketplaceChannel $channel): array
    {
        return array_merge([
            'default_category' => 'Kita',
            'export_zero_stock' => true,
            'price_multiplier' => 1,
            'feed_filename' => 'varle.xml',
            'require_category_mapping' => false,
            'allow_fallback_product_images' => false,
            'store_url' => null,
            'vat_rate' => null,
        ], $channel->config ?? []);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function resolveVatRate(array $config): float
    {
        if (isset($config['vat_rate']) && $config['vat_rate'] !== null && $config['vat_rate'] !== '') {
            return (float) $config['vat_rate'];
        }

        return (float) config('marketplace.exports.varle.vat_rate', 21);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function calculatePrimeCosts(float $priceWithVat, array $config): float
    {
        $vatRate = $this->resolveVatRate($config);

        return $priceWithVat / (1 + ($vatRate / 100));
    }

    private function startSyncJob(MarketplaceChannel $channel): SyncJob
    {
        return SyncJob::query()->create([
            'type' => 'export',
            'channel' => 'varle',
            'status' => SyncJobStatus::Running,
            'started_at' => now(),
            'context' => [
                'marketplace_channel_id' => $channel->id,
                'export_chunk_size' => $this->exportChunkSize(),
                'vat_rate' => $this->resolveVatRate($this->channelConfig($channel)),
            ],
        ]);
    }

    private function resolveManufacturer(Product $product): ?string
    {
        if (filled($product->vendor)) {
            return (string) $product->vendor;
        }

        if (filled($product->brand)) {
            return (string) $product->brand;
        }

        return null;
    }

    private function sumInventoryQuantity(ProductVariant $variant): int
    {
        return (int) $variant->inventoryLevels->sum('quantity');
    }

    private function weightInKg(ProductVariant $variant): ?string
    {
        if ($variant->weight === null) {
            return null;
        }

        $value = (float) $variant->weight;
        $unit = strtoupper((string) ($variant->weight_unit ?? ''));

        $kilograms = match ($unit) {
            'KILOGRAMS', 'KG' => $value,
            'GRAMS', 'G' => $value / 1000,
            'POUNDS', 'LB', 'LBS' => $value * 0.453592,
            'OUNCES', 'OZ' => $value * 0.0283495,
            default => $value,
        };

        return number_format($kilograms, 3, '.', '');
    }

    private function formatPrice(float $price): string
    {
        return number_format($price, 2, '.', '');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function feedRelativePath(array $config): string
    {
        $filename = (string) ($config['feed_filename'] ?? 'varle.xml');

        return 'feeds/'.$filename;
    }

    private function appendTextElement(DOMDocument $document, DOMElement $parent, string $name, string $value): void
    {
        $element = $document->createElement($name);
        $element->appendChild($document->createTextNode($value));
        $parent->appendChild($element);
    }

    private function appendCdataElement(DOMDocument $document, DOMElement $parent, string $name, string $value): void
    {
        $element = $document->createElement($name);
        $element->appendChild($document->createCDATASection($value));
        $parent->appendChild($element);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function upsertFeedFile(
        MarketplaceChannel $channel,
        array $config,
        string $relativePath,
        string $publicUrl,
    ): void {
        $filename = (string) ($config['feed_filename'] ?? 'varle.xml');

        FeedFile::query()->updateOrCreate(
            [
                'marketplace_channel_id' => $channel->id,
                'filename' => $filename,
            ],
            [
                'path' => $relativePath,
                'public_url' => $publicUrl,
                'status' => FeedFileStatus::Generated,
                'generated_at' => now(),
            ],
        );
    }

    private function finishSyncJob(SyncJob $syncJob, string $relativePath, string $publicUrl): void
    {
        $status = match (true) {
            $this->skippedVariants > 0 && $this->exportedVariants > 0 => SyncJobStatus::Partial,
            $this->skippedVariants > 0 && $this->exportedVariants === 0 => SyncJobStatus::Failed,
            default => SyncJobStatus::Completed,
        };

        $syncJob->update([
            'status' => $status,
            'finished_at' => now(),
            'failed_items' => $this->skippedVariants,
            'context' => array_merge($syncJob->context ?? [], [
                'exported_products' => $this->exportedProducts,
                'exported_variants' => $this->exportedVariants,
                'skipped_variants' => $this->skippedVariants,
                'feed_path' => $relativePath,
                'public_url' => $publicUrl,
                'warnings' => $this->warnings,
            ]),
        ]);
    }

    private function failSyncJob(SyncJob $syncJob, Throwable $exception): void
    {
        $syncJob->update([
            'status' => SyncJobStatus::Failed,
            'finished_at' => now(),
            'failed_items' => $this->skippedVariants,
            'error_message' => $exception->getMessage(),
            'context' => array_merge($syncJob->context ?? [], [
                'exported_products' => $this->exportedProducts,
                'exported_variants' => $this->exportedVariants,
                'skipped_variants' => $this->skippedVariants,
                'warnings' => $this->warnings,
            ]),
        ]);
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function recordSkippedVariant(
        SyncJob $syncJob,
        ProductVariant $variant,
        string $message,
        array $errors = [],
    ): void {
        $product = $variant->product ?? $variant->loadMissing('product')->product;

        SyncJobItem::query()->create([
            'sync_job_id' => $syncJob->id,
            'product_id' => $variant->product_id,
            'variant_id' => $variant->id,
            'sku' => $variant->sku,
            'status' => SyncJobItemStatus::Failed,
            'message' => $message,
            'payload' => array_merge(
                $product ? $this->exportContextPayload($product) : [],
                $errors !== [] ? ['errors' => $errors] : [],
            ),
        ]);

        $syncJob->increment('failed_items');
        $this->skippedVariants++;
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function recordSkippedProduct(
        SyncJob $syncJob,
        Product $product,
        string $message,
        int $variantCount,
        array $errors = [],
        ?array $payload = null,
    ): void {
        SyncJobItem::query()->create([
            'sync_job_id' => $syncJob->id,
            'product_id' => $product->id,
            'variant_id' => null,
            'sku' => null,
            'status' => SyncJobItemStatus::Failed,
            'message' => $message,
            'payload' => $payload ?? ($errors !== [] ? ['errors' => $errors] : null),
        ]);

        if ($variantCount > 0) {
            $syncJob->increment('failed_items', $variantCount);
            $this->skippedVariants += $variantCount;
        }
    }

    private function recordSkippedColorGroup(
        SyncJob $syncJob,
        Product $product,
        string $colorValue,
    ): void {
        SyncJobItem::query()->create([
            'sync_job_id' => $syncJob->id,
            'product_id' => $product->id,
            'variant_id' => null,
            'sku' => null,
            'status' => SyncJobItemStatus::Failed,
            'message' => 'Product color group skipped because all variants are invalid or missing barcode',
            'payload' => array_merge(
                $this->exportContextPayload($product),
                ['color' => $colorValue],
            ),
        ]);
    }

    /**
     * @param  array<int, array{variant: ProductVariant, quantity: int}>  $groupValidVariants
     * @param  array<string, mixed>  $config
     * @param  array{urls: array<int, string>, used_fallback: bool, variant_image_url: ?string}  $imageResolution
     */
    private function recordSkippedExportGroupForImages(
        SyncJob $syncJob,
        Product $product,
        array $groupValidVariants,
        array $config,
        array $imageResolution,
        ?string $colorValue,
    ): void {
        $firstVariant = $groupValidVariants[0]['variant'] ?? null;
        $message = VarleVariantPresenter::missingExportImagesMessage($config);

        SyncJobItem::query()->create([
            'sync_job_id' => $syncJob->id,
            'product_id' => $product->id,
            'variant_id' => $firstVariant?->id,
            'sku' => $firstVariant?->sku,
            'status' => SyncJobItemStatus::Failed,
            'message' => $message,
            'payload' => array_merge(
                $this->exportContextPayload($product),
                $this->imageExportContextPayload($product, $imageResolution),
                filled($colorValue) ? ['color' => $colorValue] : [],
            ),
        ]);

        $syncJob->increment('failed_items', count($groupValidVariants));
        $this->skippedVariants += count($groupValidVariants);
    }

    /**
     * @param  array{urls: array<int, string>, used_fallback: bool, variant_image_url: ?string}  $imageResolution
     * @return array<string, mixed>
     */
    private function imageExportContextPayload(Product $product, array $imageResolution): array
    {
        return [
            'variant_image_url' => $imageResolution['variant_image_url'] ?? '',
            'product_images_count' => $product->images->count(),
            'selected_export_images_count' => count($imageResolution['urls']),
        ];
    }

    /**
     * @param  array<int, array{variant: ProductVariant, quantity: int}>  $groupValidVariants
     */
    private function hasColorAmongVariants(array $validVariants): bool
    {
        foreach ($validVariants as $row) {
            if (filled($this->getColorValue($row['variant']))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array{variant: ProductVariant, quantity: int}>  $validVariants
     * @return array<int, array{color_value: ?string, variants: array<int, array{variant: ProductVariant, quantity: int}>}>
     */
    private function buildExportGroups(array $validVariants, bool $hasColor): array
    {
        if ($validVariants === []) {
            return [];
        }

        if (! $hasColor) {
            return [
                [
                    'color_value' => null,
                    'variants' => $validVariants,
                ],
            ];
        }

        return collect($validVariants)
            ->filter(fn (array $row): bool => filled($this->getColorValue($row['variant'])))
            ->groupBy(fn (array $row): string => (string) $this->getColorValue($row['variant']))
            ->map(fn (Collection $group, string $colorValue): array => [
                'color_value' => $colorValue,
                'variants' => $group->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{variant: ProductVariant, quantity: int}>  $validVariants
     */
    private function shouldOutputVariants(array $validVariants): bool
    {
        if (count($validVariants) > 1) {
            return true;
        }

        if ($validVariants === []) {
            return false;
        }

        return $this->getNonColorOptions($validVariants[0]['variant']) !== [];
    }

    /**
     * @param  array<int, array{variant: ProductVariant, quantity: int}>  $validVariants
     */
    private function nonColorGroupTitle(array $validVariants): string
    {
        $names = collect($this->getNonColorOptions($validVariants[0]['variant']))
            ->pluck('name')
            ->unique()
            ->values();

        if ($names->isEmpty()) {
            return 'Dydis';
        }

        return $names->implode(' / ');
    }

    private function variantDisplayTitle(ProductVariant $variant): string
    {
        $nonColorOptions = $this->getNonColorOptions($variant);

        if ($nonColorOptions !== []) {
            return collect($nonColorOptions)
                ->pluck('value')
                ->implode(' / ');
        }

        return filled($variant->title) ? (string) $variant->title : 'Default';
    }

    /**
     * @return array<int, array{name: string, value: string}>
     */
    private function getVariantOptions(ProductVariant $variant): array
    {
        $options = [];

        for ($index = 1; $index <= 3; $index++) {
            $name = $variant->{"option{$index}_name"};
            $value = $variant->{"option{$index}_value"} ?? $variant->{"option{$index}"};

            if (filled($name) && filled($value)) {
                $options[] = [
                    'name' => (string) $name,
                    'value' => (string) $value,
                ];
            }
        }

        if ($options !== []) {
            return $options;
        }

        foreach (data_get($variant->raw_payload, 'selectedOptions', []) as $option) {
            if (! is_array($option)) {
                continue;
            }

            $name = (string) ($option['name'] ?? '');
            $value = $option['value'] ?? null;

            if (filled($name) && filled($value)) {
                $options[] = [
                    'name' => $name,
                    'value' => (string) $value,
                ];
            }
        }

        return $options;
    }

    private function getColorValue(ProductVariant $variant): ?string
    {
        foreach ($this->getVariantOptions($variant) as $option) {
            if ($this->isColorOptionName($option['name'])) {
                return $option['value'];
            }
        }

        return null;
    }

    /**
     * @return array<int, array{name: string, value: string}>
     */
    private function getNonColorOptions(ProductVariant $variant): array
    {
        return array_values(array_filter(
            $this->getVariantOptions($variant),
            fn (array $option): bool => ! $this->isColorOptionName($option['name']),
        ));
    }

    private function isColorOptionName(string $name): bool
    {
        return VarleVariantPresenter::isColorOptionName($name);
    }

    /**
     * @param  array<int, array{variant: ProductVariant, quantity: int}>  $validVariants
     * @param  array<int, string>  $generatedIds
     */
    private function recordDebugProduct(
        Product $product,
        array $validVariants,
        bool $hasColor,
        array $generatedIds,
    ): void {
        $colorValues = collect($validVariants)
            ->map(fn (array $row): ?string => $this->getColorValue($row['variant']))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $this->debugLines[] = 'Handle: '.($product->handle ?? '—');
        $this->debugLines[] = '  Detected colors: '.($colorValues === [] ? 'none' : implode(', ', $colorValues));
        $this->debugLines[] = '  Color split: '.($hasColor ? 'yes' : 'no');
        $this->debugLines[] = '  Generated product IDs: '.($generatedIds === [] ? 'none' : implode(', ', $generatedIds));
        $this->debugProductCount++;
    }

    private function resetCounters(): void
    {
        $this->exportedProducts = 0;
        $this->exportedVariants = 0;
        $this->skippedVariants = 0;
        $this->warnings = [];
        $this->debugLines = [];
        $this->debugProductCount = 0;
    }

    private function resetPreviewState(): void
    {
        $this->previewSkipReasons = [];
        $this->previewPendingReviewProducts = 0;
        $this->previewExcludedProducts = 0;
        $this->previewCategoryDisabledProducts = 0;
        $this->previewUnpublishedProducts = 0;
        $this->previewMissingBarcodeVariants = 0;
        $this->previewMissingCategoryProducts = 0;
        $this->previewFallbackCategoryProducts = 0;
        $this->previewExportableProducts = 0;
        $this->previewExportableVariants = 0;
        $this->previewSkippedVariants = 0;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function previewProduct(Product $product, MarketplaceChannel $channel, array $config): void
    {
        if ($product->status !== \App\Enums\ProductStatus::Active) {
            $this->previewUnpublishedProducts++;
        }

        $gate = $this->exportGatekeeper->assess($product, $channel);

        if (! $gate->allowed) {
            $this->recordPreviewSkip((string) $gate->skipMessage);

            match ($gate->skipMessage) {
                'Product pending Varle review' => $this->previewPendingReviewProducts++,
                'Product excluded from Varle export' => $this->previewExcludedProducts++,
                'Category mapping disabled for Varle export' => $this->previewCategoryDisabledProducts++,
                default => null,
            };

            $this->previewSkippedVariants += $product->variants->count();

            return;
        }

        $categoryExplanation = $gate->categoryExplanation ?? $this->categoryResolver->explain($product, $channel);

        if (blank($categoryExplanation['resolved_category'])) {
            $this->previewMissingCategoryProducts++;
        } elseif ($categoryExplanation['fallback_used']) {
            $this->previewFallbackCategoryProducts++;
        }

        $productValidation = $this->validator->validateProduct(
            $product,
            $channel,
            $config,
            $categoryExplanation,
        );

        if (! $productValidation->isValid) {
            $this->recordPreviewSkip($productValidation->message());
            $this->previewSkippedVariants += $product->variants->count();

            return;
        }

        $validVariants = $this->collectValidVariantsForPreview($product, $product->variants->all(), $config);
        $exportGroups = $this->buildExportGroups($validVariants, $this->hasColorAmongVariants($validVariants));
        $exportedGroupCount = 0;
        $exportedVariantCount = 0;

        foreach ($exportGroups as $exportGroup) {
            $groupValidVariants = $exportGroup['variants'];

            if ($groupValidVariants === []) {
                continue;
            }

            $imageResolution = VarleVariantPresenter::resolveExportImageUrls(
                $product,
                $groupValidVariants,
                $config,
            );

            if ($imageResolution['urls'] === []) {
                $this->recordPreviewSkip(VarleVariantPresenter::missingExportImagesMessage($config));
                $this->previewSkippedVariants += count($groupValidVariants);

                continue;
            }

            $exportedGroupCount++;
            $exportedVariantCount += count($groupValidVariants);
        }

        if ($exportedGroupCount > 0) {
            $this->previewExportableProducts++;
            $this->previewExportableVariants += $exportedVariantCount;
        } elseif ($product->variants->isNotEmpty()) {
            $this->recordPreviewSkip('Product skipped because all variants are invalid or missing barcode');
            $this->previewSkippedVariants += $product->variants->count();
        }
    }

    /**
     * @param  array<int, ProductVariant>  $variants
     * @param  array<string, mixed>  $config
     * @return array<int, array{variant: ProductVariant, quantity: int}>
     */
    private function collectValidVariantsForPreview(
        Product $product,
        array $variants,
        array $config,
    ): array {
        $validVariants = [];

        foreach ($variants as $variant) {
            if (blank($variant->barcode)) {
                $this->previewMissingBarcodeVariants++;
                $this->previewSkippedVariants++;
                $this->recordPreviewSkip('Missing barcode');

                continue;
            }

            $variantValidation = $this->validator->validateVariant($variant, $config);

            if (! $variantValidation->isValid) {
                $this->previewSkippedVariants++;
                $this->recordPreviewSkip($variantValidation->message());

                continue;
            }

            $validVariants[] = [
                'variant' => $variant,
                'quantity' => $this->sumInventoryQuantity($variant),
            ];
        }

        return $validVariants;
    }

    private function recordPreviewSkip(string $message): void
    {
        $this->previewSkipReasons[$message] = ($this->previewSkipReasons[$message] ?? 0) + 1;
    }

    private function buildPreviewResult(): VarleExportPreviewResult
    {
        arsort($this->previewSkipReasons);

        return new VarleExportPreviewResult(
            exportableProducts: $this->previewExportableProducts,
            exportableVariants: $this->previewExportableVariants,
            skippedVariants: $this->previewSkippedVariants,
            pendingReviewProducts: $this->previewPendingReviewProducts,
            excludedProducts: $this->previewExcludedProducts,
            categoryDisabledProducts: $this->previewCategoryDisabledProducts,
            unpublishedProducts: $this->previewUnpublishedProducts,
            missingBarcodeVariants: $this->previewMissingBarcodeVariants,
            missingCategoryProducts: $this->previewMissingCategoryProducts,
            fallbackCategoryProducts: $this->previewFallbackCategoryProducts,
            topSkipReasons: array_slice($this->previewSkipReasons, 0, 10, true),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function exportContextPayload(Product $product, ?VarleExportGateResult $gate = null): array
    {
        $gate ??= $this->exportGatekeeper->assess(
            $product,
            $this->resolveChannel(),
        );

        return [
            'varle_export_status' => $product->varle_export_status?->value ?? (string) $product->varle_export_status,
            'category_mapping_export_enabled' => $gate->categoryMappingExportEnabled,
            'product_is_published' => $product->status === \App\Enums\ProductStatus::Active,
            'product_published_at' => $product->status === \App\Enums\ProductStatus::Active
                ? $product->imported_at?->toDateTimeString()
                : null,
        ];
    }
}
