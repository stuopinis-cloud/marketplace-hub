<?php

namespace App\Services\Marketplace\Pigu;

use App\Enums\FeedFileStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Marketplace\ProductAvailabilityResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Separate PHH "stock and prices" feed.
 * Product catalog XML must not include prices; this feed matches modifications by sku/ean.
 *
 * Schema (single-market discount mechanism docs):
 * sku, ean, price-before-discount, price-after-discount, stock, collectionhours
 *
 * Default mode keeps product-readiness gating (category mapping, description, etc.).
 * --all-valid mode exports every Shopify variant with valid sku/ean/price/stock only.
 */
class PiguOfferFeedExporter
{
    public const string HOURS_SOURCE_LOCAL = 'local';

    public const string HOURS_SOURCE_SUPPLIER = 'supplier';

    public const string HOURS_SOURCE_SUPPLIER_ONLY = 'supplier_only';

    public const string HOURS_SOURCE_BACKORDER = 'backorder';

    public const string FAILED_CSV_PATH = 'exports/pigu_offers_failed_latest.csv';

    public const string PREVIEW_DISK = 'local';

    public const string PRODUCTION_FEED_RELATIVE = 'feeds/pigu-offers.xml';

    private const int MAX_PREVIEW_EXAMPLES = 8;

    /** @var array<string, int> */
    private array $seenEans = [];

    /** @var array<string, true> */
    private array $seenSkus = [];

    /** @var resource|null */
    private $skippedCsvHandle = null;

    /** @var list<array<string, mixed>> */
    private array $exampleOffers = [];

    /** @var array<string, int> */
    private array $previewSkippedByReason = [];

    /** @var array<string, true> */
    private array $diagnosticSkuLookup = [];

    /** @var array<string, array<string, mixed>> */
    private array $diagnosticResults = [];

    private int $previewShopifyMatched = 0;

    private int $previewSupplierOnly = 0;

    private int $previewDeduplicated = 0;

    private int $consideredVariants = 0;

    private int $consideredSupplierOnly = 0;

    private int $duplicateEanCount = 0;

    private ?PiguExportOptions $activeOptions = null;

    /** @var (callable(array<string, mixed>): void)|null */
    private $progressCallback = null;

    /** @var array<int, Supplier|null> */
    private array $supplierCache = [];

    public function __construct(
        private readonly PiguProductFeedExporter $products,
        private readonly PiguReadinessService $readiness,
        private readonly ProductAvailabilityResolver $availability,
        private readonly PiguSupplierPriceCalculator $priceCalculator = new PiguSupplierPriceCalculator,
    ) {}

    public static function previewDirectory(int $supplierId, string $scope = PiguExportOptions::PREVIEW_SCOPE_CONTRIBUTION): string
    {
        $base = 'pigu-previews/supplier-'.$supplierId;

        return $scope === PiguExportOptions::PREVIEW_SCOPE_COMBINED
            ? $base.'/combined'
            : $base;
    }

    /**
     * @param  (callable(array<string, mixed>): void)|null  $onProgress
     * @return array{
     *     channel_id: int,
     *     feed_path: string,
     *     absolute_path: string,
     *     public_url: string|null,
     *     disk: string,
     *     preview: bool,
     *     preview_supplier_id: int|null,
     *     filter: array{easy_only: bool, all_valid: bool},
     *     variants_considered: int,
     *     offers_exported: int,
     *     offers_skipped: int,
     *     skipped_by_code: array<string, int>,
     *     offers_by_collectionhours: array<string, int>,
     *     local_offers_count: int,
     *     supplier_offers_count: int,
     *     supplier_only_offers_count: int,
     *     backorder_offers_count: int,
     *     unique_sku_count: int,
     *     unique_ean_count: int,
     *     duplicate_ean_count: int,
     *     failed_csv_path: string,
     *     examples: list<array<string, mixed>>,
     *     preview_stats: array{
     *         shopify_matched_offers: int,
     *         supplier_only_offers: int,
     *         deduplicated_offers: int,
     *         skipped_by_reason: array<string, int>
     *     }
     * }
     */
    public function export(?PiguExportOptions $options = null, ?callable $onProgress = null): array
    {
        $options ??= new PiguExportOptions;
        $this->activeOptions = $options;
        $this->progressCallback = $onProgress;
        $this->seenEans = [];
        $this->seenSkus = [];
        $this->exampleOffers = [];
        $this->previewSkippedByReason = [];
        $this->previewShopifyMatched = 0;
        $this->previewSupplierOnly = 0;
        $this->previewDeduplicated = 0;
        $this->consideredVariants = 0;
        $this->consideredSupplierOnly = 0;
        $this->duplicateEanCount = 0;
        $this->supplierCache = [];
        $this->skippedCsvHandle = null;
        $this->diagnosticResults = [];
        $this->diagnosticSkuLookup = [];

        if ($options->preview && $options->previewSupplierId === null) {
            throw new RuntimeException('Preview export requires preview_supplier_id.');
        }

        foreach ((array) config('marketplace.exports.pigu.preview.diagnostic_skus', []) as $sku) {
            $sku = trim((string) $sku);
            if ($sku !== '') {
                $this->diagnosticSkuLookup[$sku] = true;
            }
        }

        $channel = $this->products->resolveChannel();
        $config = $this->products->channelConfig($channel);

        $isPreview = $options->isPreview();
        $diskName = $isPreview ? self::PREVIEW_DISK : 'public';
        $disk = Storage::disk($diskName);

        if ($isPreview) {
            $previewDir = self::previewDirectory((int) $options->previewSupplierId, $options->previewScope);
            $relativePath = $previewDir.'/offers.xml';
            $tempPath = $previewDir.'/offers.xml.tmp';
            $failedCsvRelative = $previewDir.'/skipped.csv';
            $disk->makeDirectory($previewDir);
        } else {
            $relativePath = (string) ($config['offers_feed_path'] ?? self::PRODUCTION_FEED_RELATIVE);
            $tempPath = (string) ($config['offers_feed_temp_path'] ?? 'feeds/pigu-offers.xml.tmp');
            $failedCsvRelative = (string) ($config['offers_failed_csv_path'] ?? self::FAILED_CSV_PATH);
            $disk->makeDirectory('feeds');
        }

        $disk->delete($tempPath);
        $disk->delete($failedCsvRelative);

        $tempAbsolute = $disk->path($tempPath);
        $finalAbsolute = $disk->path($relativePath);
        $failedCsvAbsolute = $disk->path($failedCsvRelative);

        File::ensureDirectoryExists(dirname($tempAbsolute), 0755);
        File::ensureDirectoryExists(dirname($failedCsvAbsolute), 0755);
        File::ensureDirectoryExists(dirname($finalAbsolute), 0755);

        $this->openSkippedCsvStream($failedCsvAbsolute);

        $exported = 0;
        $skipped = 0;
        $skippedByCode = [];
        $offersByCollectionHours = [];
        $localOffers = 0;
        $supplierOffers = 0;
        $supplierOnlyOffers = 0;
        $backorderOffers = 0;

        $handle = fopen($tempAbsolute, 'w');
        if ($handle === false) {
            $this->closeSkippedCsvStream();
            throw new RuntimeException('Unable to open Pigu offers feed temp file for writing.');
        }

        try {
            fwrite($handle, '<?xml version="1.0" encoding="UTF-8"?>'."\n");
            fwrite($handle, "<products>\n");

            $with = ['variants.inventoryLevels', 'variants.supplierProducts.supplier'];
            if (! $options->allValid) {
                $with[] = 'sourceCategories';
            }

            $query = Product::query()
                ->with($with)
                ->orderBy('id');

            $easyEligibleHandles = (! $options->allValid && $options->easyOnly)
                ? $this->products->easyOnlyCollectionHandles($channel)
                : null;

            if (! $options->allValid && $options->easyOnly) {
                $handles = $easyEligibleHandles ?? [];
                if ($handles === []) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereHas('sourceCategories', function ($builder) use ($handles): void {
                        $builder->where('type', 'collection')->whereIn('handle', $handles);
                    });
                }
            }

            $chunkSize = max(25, (int) ($config['export_chunk_size'] ?? 100));

            $query->chunkById($chunkSize, function ($products) use (
                $handle,
                $channel,
                $options,
                $easyEligibleHandles,
                $config,
                &$exported,
                &$skipped,
                &$skippedByCode,
                &$offersByCollectionHours,
                &$localOffers,
                &$supplierOffers,
                &$backorderOffers,
            ): void {
                foreach ($products as $product) {
                    if (! $options->allValid) {
                        $readiness = $this->readiness->analyzeProduct($product, $channel, $easyEligibleHandles);

                        if (! $readiness['exportable']) {
                            foreach ($product->variants as $variant) {
                                $this->consideredVariants++;
                                $skipped++;
                                $code = 'not_exportable';
                                $skippedByCode[$code] = ($skippedByCode[$code] ?? 0) + 1;
                                $this->recordSkip($product, $variant, $code, $this->availability->resolve($variant));
                            }

                            continue;
                        }
                    }

                    foreach ($product->variants as $variant) {
                        $this->consideredVariants++;
                        $built = $this->buildOfferRow($product, $variant, $config);

                        if (! ($built['ok'] ?? false)) {
                            $code = (string) ($built['code'] ?? 'invalid_offer');
                            $skipped++;
                            $skippedByCode[$code] = ($skippedByCode[$code] ?? 0) + 1;
                            if ($code === 'duplicate_ean') {
                                $this->duplicateEanCount++;
                            }
                            $this->recordSkip(
                                $product,
                                $variant,
                                $code,
                                is_array($built['resolved'] ?? null) ? $built['resolved'] : $this->availability->resolve($variant),
                            );

                            continue;
                        }

                        $this->trackExportedOffer($built);

                        // Preview XML contains only this supplier's contribution; production writes all.
                        if ($this->shouldWriteOfferXml($built, $options)) {
                            fwrite($handle, $built['xml']);
                            $exported++;

                            $hoursKey = (string) $built['collection_hours'];
                            $offersByCollectionHours[$hoursKey] = ($offersByCollectionHours[$hoursKey] ?? 0) + 1;

                            match ($built['hours_source']) {
                                self::HOURS_SOURCE_LOCAL => $localOffers++,
                                self::HOURS_SOURCE_SUPPLIER => $supplierOffers++,
                                self::HOURS_SOURCE_BACKORDER => $backorderOffers++,
                                default => null,
                            };
                        }

                        $sku = (string) $built['sku'];
                        $ean = (string) $built['ean'];
                        $this->seenSkus[$sku] = true;
                        $this->seenEans[$ean] = $variant->id;
                    }
                }

                $this->reportProgress([
                    'phase' => 'shopify',
                    'products_processed' => $this->consideredVariants,
                    'offers_generated' => $exported,
                    'rejected' => $skipped,
                ]);

                // Free chunk models before the next DB page.
                unset($products);
            });

            // Supplier-only offers (no Shopify product) from Pigu-enabled XML suppliers.
            $this->exportSupplierOnlyOffers(
                $handle,
                $config,
                $exported,
                $skipped,
                $skippedByCode,
                $offersByCollectionHours,
                $supplierOnlyOffers,
            );

            fwrite($handle, "</products>\n");
        } finally {
            fclose($handle);
            $this->closeSkippedCsvStream();
        }

        $this->assertWellFormedXmlFile($tempAbsolute);
        if (is_file($finalAbsolute)) {
            File::delete($finalAbsolute);
        }
        File::move($tempAbsolute, $finalAbsolute);

        $offersPublicUrl = null;

        if (! $isPreview) {
            $offersFilename = 'pigu-offers.xml';
            $offersPath = self::PRODUCTION_FEED_RELATIVE;
            $offersPublicUrl = 'https://hub.gudle.lt/feeds/pigu-offers.xml';

            $now = now();

            DB::table('feed_files')->updateOrInsert(
                [
                    'marketplace_channel_id' => $channel->id,
                    'filename' => $offersFilename,
                ],
                [
                    'path' => $offersPath,
                    'public_url' => $offersPublicUrl,
                    'status' => FeedFileStatus::Generated->value,
                    'generated_at' => $now,
                    'created_at' => DB::table('feed_files')
                        ->where('marketplace_channel_id', $channel->id)
                        ->where('filename', $offersFilename)
                        ->value('created_at') ?: $now,
                    'updated_at' => $now,
                ],
            );
        }

        ksort($offersByCollectionHours, SORT_NUMERIC);
        ksort($skippedByCode);
        ksort($this->previewSkippedByReason);

        $absolutePath = $disk->path($relativePath);

        $this->reportProgress([
            'phase' => 'done',
            'products_processed' => $this->consideredVariants + $this->consideredSupplierOnly,
            'offers_generated' => $exported,
            'rejected' => $skipped,
        ]);

        return [
            'channel_id' => $channel->id,
            'feed_path' => $relativePath,
            'absolute_path' => $absolutePath,
            'public_url' => $offersPublicUrl,
            'disk' => $diskName,
            'preview' => $isPreview,
            'preview_supplier_id' => $options->previewSupplierId,
            'filter' => [
                'easy_only' => $options->easyOnly,
                'all_valid' => $options->allValid,
            ],
            'variants_considered' => $this->consideredVariants,
            'offers_exported' => $exported,
            'offers_skipped' => $skipped,
            'skipped_by_code' => $skippedByCode,
            'offers_by_collectionhours' => $offersByCollectionHours,
            'local_offers_count' => $localOffers,
            'supplier_offers_count' => $supplierOffers,
            'supplier_only_offers_count' => $supplierOnlyOffers,
            'backorder_offers_count' => $backorderOffers,
            'supplier_only_considered' => $this->consideredSupplierOnly,
            'unique_sku_count' => count($this->seenSkus),
            'unique_ean_count' => count($this->seenEans),
            'duplicate_ean_count' => $this->duplicateEanCount,
            'failed_csv_path' => $failedCsvRelative,
            'examples' => $this->exampleOffers,
            'preview_stats' => [
                'shopify_matched_offers' => $this->previewShopifyMatched,
                'supplier_only_offers' => $this->previewSupplierOnly,
                'deduplicated_offers' => $this->previewDeduplicated,
                'skipped_by_reason' => $this->previewSkippedByReason,
            ],
            'diagnostics' => $this->diagnosticResults,
            'preview_scope' => $isPreview ? $options->previewScope : null,
        ];
    }

    /**
     * Append offers for supplier products that have no Shopify variant match.
     *
     * @param  resource  $handle
     * @param  array<string, mixed>  $config
     * @param  array<string, int>  $skippedByCode
     * @param  array<string, int>  $offersByCollectionHours
     */
    private function exportSupplierOnlyOffers(
        $handle,
        array $config,
        int &$exported,
        int &$skipped,
        array &$skippedByCode,
        array &$offersByCollectionHours,
        int &$supplierOnlyOffers,
    ): void {
        unset($config);

        $options = $this->activeOptions ?? new PiguExportOptions;

        $suppliers = Supplier::query()
            ->where('enabled', true)
            ->orderBy('stock_priority')
            ->orderBy('id')
            ->get()
            ->filter(fn (Supplier $supplier): bool => $this->isPiguOffersActive($supplier)
                && PiguSupplierOfferConfig::exportSupplierOnly($supplier));

        foreach ($suppliers as $supplier) {
            SupplierProduct::query()
                ->where('supplier_id', $supplier->id)
                ->whereNull('product_variant_id')
                ->where('enabled', true)
                ->orderBy('id')
                ->chunkById(200, function ($products) use (
                    $handle,
                    $supplier,
                    $options,
                    &$exported,
                    &$skipped,
                    &$skippedByCode,
                    &$offersByCollectionHours,
                    &$supplierOnlyOffers,
                ): void {
                    foreach ($products as $product) {
                        $this->consideredSupplierOnly++;
                        $built = $this->buildSupplierOnlyOfferRow($product, $supplier);

                        if (! ($built['ok'] ?? false)) {
                            $code = (string) ($built['code'] ?? 'invalid_offer');
                            $skipped++;
                            $skippedByCode[$code] = ($skippedByCode[$code] ?? 0) + 1;
                            if ($code === 'duplicate_ean') {
                                $this->duplicateEanCount++;
                            }
                            $this->recordSupplierOnlySkip($product, $supplier, $code);

                            continue;
                        }

                        $this->trackExportedOffer($built);

                        if ($this->shouldWriteOfferXml($built, $options)) {
                            fwrite($handle, $built['xml']);
                            $exported++;
                            $supplierOnlyOffers++;

                            $hoursKey = (string) $built['collection_hours'];
                            $offersByCollectionHours[$hoursKey] = ($offersByCollectionHours[$hoursKey] ?? 0) + 1;
                        }

                        $this->seenSkus[(string) $built['sku']] = true;
                        $this->seenEans[(string) $built['ean']] = -1 * (int) $product->id;
                    }

                    $this->reportProgress([
                        'phase' => 'supplier_only',
                        'products_processed' => $this->consideredVariants + $this->consideredSupplierOnly,
                        'offers_generated' => $exported,
                        'rejected' => $skipped,
                    ]);
                });
        }
    }

    /**
     * @return array{
     *     ok: true,
     *     xml: string,
     *     sku: string,
     *     ean: string,
     *     collection_hours: int,
     *     hours_source: string
     * }|array{ok: false, code: string}
     */
    private function buildSupplierOnlyOfferRow(SupplierProduct $product, Supplier $supplier): array
    {
        if ($product->isStale()) {
            return ['ok' => false, 'code' => 'stale_supplier_stock'];
        }

        $sku = trim((string) ($product->supplier_sku ?? ''));
        $rawBarcode = trim((string) ($product->barcode ?? ''));
        $ean = preg_replace('/\D+/', '', $rawBarcode) ?? '';
        $stock = max(0, (int) ($product->stock_quantity ?? 0));
        $price = $this->priceCalculator->calculate(
            $product->price !== null ? (float) $product->price : null,
            $supplier,
        );
        $hours = PiguSupplierOfferConfig::collectionHours($supplier);

        if ($sku === '') {
            return ['ok' => false, 'code' => 'missing_sku'];
        }

        if ($rawBarcode === '' || $ean === '') {
            return ['ok' => false, 'code' => 'missing_ean'];
        }

        if (! preg_match('/^\d{11,13}$/', $ean)) {
            return ['ok' => false, 'code' => 'invalid_ean'];
        }

        if (isset($this->seenEans[$ean])) {
            return ['ok' => false, 'code' => 'duplicate_ean'];
        }

        if (isset($this->seenSkus[$sku])) {
            return ['ok' => false, 'code' => 'duplicate_sku'];
        }

        if ($price === null || $price <= 0) {
            return ['ok' => false, 'code' => 'invalid_price'];
        }

        if ($stock <= 0) {
            return ['ok' => false, 'code' => 'no_exportable_stock'];
        }

        $xml = "  <product>\n";
        $xml .= '    <sku>'.$this->cdata($sku)."</sku>\n";
        $xml .= '    <ean>'.$this->cdata($ean)."</ean>\n";
        $xml .= '    <price-before-discount>'.$this->decimal($price)."</price-before-discount>\n";
        $xml .= '    <price-after-discount>'.$this->decimal($price)."</price-after-discount>\n";
        $xml .= '    <stock>'.$stock."</stock>\n";
        $xml .= '    <collectionhours>'.$hours."</collectionhours>\n";
        $xml .= "  </product>\n";

        return [
            'ok' => true,
            'xml' => $xml,
            'sku' => $sku,
            'ean' => $ean,
            'price_before' => $price,
            'price_after' => $price,
            'stock' => $stock,
            'collection_hours' => $hours,
            'hours_source' => self::HOURS_SOURCE_SUPPLIER_ONLY,
            'supplier_id' => $supplier->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{
     *     ok: true,
     *     xml: string,
     *     sku: string,
     *     ean: string,
     *     collection_hours: int,
     *     hours_source: string
     * }|array{
     *     ok: false,
     *     code: string,
     *     resolved?: array<string, mixed>
     * }
     */
    private function buildOfferRow(Product $product, ProductVariant $variant, array $config): array
    {
        $resolved = $this->availability->resolve($variant);
        $sku = trim((string) ($variant->sku ?? ''));
        $rawBarcode = trim((string) ($variant->barcode ?? ''));
        $ean = preg_replace('/\D+/', '', $rawBarcode) ?? '';
        $price = $variant->price !== null ? (float) $variant->price : null;
        $compare = $variant->compare_at_price !== null ? (float) $variant->compare_at_price : null;

        if ($this->isExcludedFromPigu($product, $variant)) {
            return ['ok' => false, 'code' => 'excluded_from_pigu', 'resolved' => $resolved];
        }

        if ($sku === '') {
            return ['ok' => false, 'code' => 'missing_sku', 'resolved' => $resolved];
        }

        if ($rawBarcode === '' || $ean === '') {
            return ['ok' => false, 'code' => 'missing_ean', 'resolved' => $resolved];
        }

        if (! preg_match('/^\d{11,13}$/', $ean)) {
            return ['ok' => false, 'code' => 'invalid_ean', 'resolved' => $resolved];
        }

        if (isset($this->seenEans[$ean])) {
            return ['ok' => false, 'code' => 'duplicate_ean', 'resolved' => $resolved];
        }

        if ($price === null) {
            return ['ok' => false, 'code' => 'missing_price', 'resolved' => $resolved];
        }

        if ($price <= 0) {
            return ['ok' => false, 'code' => 'invalid_price', 'resolved' => $resolved];
        }

        $hours = $this->resolveCollectionHours($resolved, $config);
        if ($hours === null || $hours['hours'] <= 0) {
            return ['ok' => false, 'code' => 'no_exportable_stock', 'resolved' => $resolved];
        }

        $stock = $this->resolveExportStock($resolved, $hours['source']);
        if ($stock <= 0) {
            return ['ok' => false, 'code' => 'no_exportable_stock', 'resolved' => $resolved];
        }

        $before = $compare !== null && $compare > $price ? $compare : $price;
        $after = $price;

        $xml = "  <product>\n";
        $xml .= '    <sku>'.$this->cdata($sku)."</sku>\n";
        $xml .= '    <ean>'.$this->cdata($ean)."</ean>\n";
        $xml .= '    <price-before-discount>'.$this->decimal($before)."</price-before-discount>\n";
        $xml .= '    <price-after-discount>'.$this->decimal($after)."</price-after-discount>\n";
        $xml .= '    <stock>'.$stock."</stock>\n";
        $xml .= '    <collectionhours>'.$hours['hours']."</collectionhours>\n";
        $xml .= "  </product>\n";

        return [
            'ok' => true,
            'xml' => $xml,
            'sku' => $sku,
            'ean' => $ean,
            'price_before' => $before,
            'price_after' => $after,
            'stock' => $stock,
            'collection_hours' => $hours['hours'],
            'hours_source' => $hours['source'],
            'supplier_id' => isset($resolved['supplier_id']) ? (int) $resolved['supplier_id'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $resolved
     */
    private function resolveExportStock(array $resolved, string $hoursSource): int
    {
        return match ($hoursSource) {
            self::HOURS_SOURCE_BACKORDER => 1,
            default => max(0, (int) ($resolved['quantity'] ?? 0)),
        };
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @param  array<string, mixed>  $config
     * @return array{hours: int, source: string}|null
     */
    public function resolveCollectionHours(array $resolved, array $config): ?array
    {
        $localHours = max(1, (int) ($config['local_collection_hours'] ?? 24));
        $supplierHours = max(1, (int) ($config['supplier_collection_hours'] ?? 168));
        $backorderHours = max(1, (int) ($config['backorder_collection_hours'] ?? 240));

        $sourceType = $resolved['source_type'] ?? $resolved['source'] ?? null;
        $localQuantity = (int) ($resolved['local_quantity'] ?? 0);
        $quantity = (int) ($resolved['quantity'] ?? 0);

        if ($localQuantity > 0 || $sourceType === ProductAvailabilityResolver::SOURCE_SHOPIFY) {
            return ['hours' => $localHours, 'source' => self::HOURS_SOURCE_LOCAL];
        }

        if (in_array($sourceType, [
            ProductAvailabilityResolver::SOURCE_SUPPLIER,
            ProductAvailabilityResolver::SOURCE_SUPPLIER_AVAILABILITY_FALLBACK,
        ], true)) {
            $hours = $this->resolveSupplierCollectionHours(
                isset($resolved['supplier_id']) ? (int) $resolved['supplier_id'] : null,
                $supplierHours,
            );

            return ['hours' => $hours, 'source' => self::HOURS_SOURCE_SUPPLIER];
        }

        if ($sourceType === ProductAvailabilityResolver::SOURCE_BACKORDER) {
            return ['hours' => $backorderHours, 'source' => self::HOURS_SOURCE_BACKORDER];
        }

        if ($quantity > 0) {
            // Defensive fallback for unexpected exportable sources.
            return ['hours' => $localHours, 'source' => self::HOURS_SOURCE_LOCAL];
        }

        return null;
    }

    private function resolveSupplierCollectionHours(?int $supplierId, int $defaultHours): int
    {
        if ($supplierId === null) {
            return $defaultHours;
        }

        $supplier = $this->supplierCache[$supplierId] ??= Supplier::query()->find($supplierId);

        if (! $supplier instanceof Supplier || ! $this->isPiguOffersActive($supplier)) {
            return $defaultHours;
        }

        return PiguSupplierOfferConfig::collectionHours($supplier);
    }

    /**
     * Treat preview focus supplier as Pigu-enabled without mutating saved config.
     */
    public function isPiguOffersActive(Supplier $supplier): bool
    {
        if (PiguSupplierOfferConfig::isEnabled($supplier)) {
            return true;
        }

        $options = $this->activeOptions;

        return $options !== null
            && $options->isPreview()
            && $options->previewSupplierId === $supplier->id;
    }

    /**
     * @param  array<string, mixed>  $built
     */
    private function shouldWriteOfferXml(array $built, PiguExportOptions $options): bool
    {
        if (! $options->isPreview() || $options->isCombinedPreview()) {
            return true;
        }

        $hoursSource = (string) ($built['hours_source'] ?? '');
        $supplierId = isset($built['supplier_id']) ? (int) $built['supplier_id'] : null;

        if ($supplierId !== $options->previewSupplierId) {
            return false;
        }

        return in_array($hoursSource, [
            self::HOURS_SOURCE_SUPPLIER,
            self::HOURS_SOURCE_SUPPLIER_ONLY,
        ], true);
    }

    /**
     * Track contribution/combined stats and diagnostics without retaining every offer row.
     *
     * @param  array<string, mixed>  $built
     */
    private function trackExportedOffer(array $built): void
    {
        $options = $this->activeOptions;
        if ($options === null || ! $options->isPreview()) {
            return;
        }

        if (! $this->shouldWriteOfferXml($built, $options)) {
            return;
        }

        $sku = (string) ($built['sku'] ?? '');
        if ($sku !== '' && isset($this->diagnosticSkuLookup[$sku])) {
            $this->diagnosticResults[$sku] = [
                'sku' => $sku,
                'ean' => (string) ($built['ean'] ?? ''),
                'stock' => isset($built['stock']) ? (int) $built['stock'] : null,
                'collectionhours' => isset($built['collection_hours']) ? (int) $built['collection_hours'] : null,
                'price' => $built['price_after'] ?? $built['price_before'] ?? null,
                'hours_source' => $built['hours_source'] ?? null,
                'supplier_id' => isset($built['supplier_id']) ? (int) $built['supplier_id'] : null,
            ];
        }

        $isFocusSupplierOffer = isset($built['supplier_id'])
            && (int) $built['supplier_id'] === (int) $options->previewSupplierId
            && in_array($built['hours_source'] ?? null, [
                self::HOURS_SOURCE_SUPPLIER,
                self::HOURS_SOURCE_SUPPLIER_ONLY,
            ], true);

        if ($isFocusSupplierOffer || $options->isContributionPreview()) {
            if (($built['hours_source'] ?? null) === self::HOURS_SOURCE_SUPPLIER) {
                $this->previewShopifyMatched++;
            }

            if (($built['hours_source'] ?? null) === self::HOURS_SOURCE_SUPPLIER_ONLY) {
                $this->previewSupplierOnly++;
            }
        }

        if (count($this->exampleOffers) >= self::MAX_PREVIEW_EXAMPLES) {
            return;
        }

        $this->exampleOffers[] = [
            'sku' => $sku,
            'ean' => (string) ($built['ean'] ?? ''),
            'price' => $built['price_after'] ?? $built['price_before'] ?? null,
            'stock' => isset($built['stock']) ? (int) $built['stock'] : null,
            'collectionhours' => isset($built['collection_hours']) ? (int) $built['collection_hours'] : null,
            'hours_source' => $built['hours_source'] ?? null,
        ];
    }

    private function isExcludedFromPigu(Product $product, ProductVariant $variant): bool
    {
        // No dedicated Pigu exclusion flag exists yet; keep a hook for future rules.
        unset($product, $variant);

        return false;
    }

    /**
     * @param  array<string, mixed>  $resolved
     */
    private function recordSkip(Product $product, ProductVariant $variant, string $code, array $resolved): void
    {
        $supplierId = isset($resolved['supplier_id']) ? (int) $resolved['supplier_id'] : null;

        if ($supplierId === null) {
            $matched = $variant->relationLoaded('supplierProducts')
                ? $variant->supplierProducts->first()
                : null;
            if ($matched instanceof SupplierProduct) {
                $supplierId = (int) $matched->supplier_id;
            }
        }

        $this->streamSkipRow([
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'product_title' => $product->title,
            'variant_title' => $variant->title,
            'vendor' => $product->vendor,
            'sku' => $variant->sku,
            'ean' => $variant->barcode,
            'price' => $variant->price,
            'local_stock' => (int) ($resolved['local_quantity'] ?? 0),
            'supplier_stock' => $resolved['supplier_quantity'] ?? null,
            'inventory_policy' => $variant->inventory_policy,
            'stock_source' => $resolved['source_type'] ?? $resolved['source'] ?? null,
            'skip_reason' => $code,
            'supplier_id' => $supplierId,
        ]);
    }

    private function recordSupplierOnlySkip(SupplierProduct $product, Supplier $supplier, string $code): void
    {
        $this->streamSkipRow([
            'product_id' => null,
            'variant_id' => null,
            'product_title' => $product->title ?? $supplier->name,
            'variant_title' => null,
            'vendor' => $supplier->name,
            'sku' => $product->supplier_sku,
            'ean' => $product->barcode,
            'price' => $product->price,
            'local_stock' => 0,
            'supplier_stock' => $product->stock_quantity,
            'inventory_policy' => null,
            'stock_source' => self::HOURS_SOURCE_SUPPLIER_ONLY,
            'skip_reason' => $code,
            'supplier_id' => $supplier->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function streamSkipRow(array $row): void
    {
        $options = $this->activeOptions ?? new PiguExportOptions;

        if ($options->isContributionPreview()) {
            if ((int) ($row['supplier_id'] ?? 0) !== (int) $options->previewSupplierId) {
                return;
            }

            $code = (string) ($row['skip_reason'] ?? 'unknown');
            $this->previewSkippedByReason[$code] = ($this->previewSkippedByReason[$code] ?? 0) + 1;
            if (in_array($code, ['duplicate_ean', 'duplicate_sku'], true)) {
                $this->previewDeduplicated++;
            }
        } elseif ($options->isCombinedPreview()) {
            $code = (string) ($row['skip_reason'] ?? 'unknown');
            $this->previewSkippedByReason[$code] = ($this->previewSkippedByReason[$code] ?? 0) + 1;
            if (in_array($code, ['duplicate_ean', 'duplicate_sku'], true)) {
                $this->previewDeduplicated++;
            }
        }

        if (! is_resource($this->skippedCsvHandle)) {
            return;
        }

        fputcsv($this->skippedCsvHandle, [
            $row['product_id'],
            $row['variant_id'],
            $row['product_title'],
            $row['variant_title'],
            $row['vendor'],
            $row['sku'],
            $row['ean'],
            $row['price'],
            $row['local_stock'],
            $row['supplier_stock'],
            $row['inventory_policy'],
            $row['stock_source'],
            $row['skip_reason'],
            $row['supplier_id'] ?? null,
        ]);
    }

    private function openSkippedCsvStream(string $absolutePath): void
    {
        $handle = fopen($absolutePath, 'w');
        if ($handle === false) {
            throw new RuntimeException('Unable to open Pigu offers skipped CSV for writing.');
        }

        $this->skippedCsvHandle = $handle;

        fputcsv($handle, [
            'product_id',
            'variant_id',
            'product_title',
            'variant_title',
            'vendor',
            'sku',
            'ean',
            'price',
            'local_stock',
            'supplier_stock',
            'inventory_policy',
            'stock_source',
            'skip_reason',
            'supplier_id',
        ]);
    }

    private function closeSkippedCsvStream(): void
    {
        if (is_resource($this->skippedCsvHandle)) {
            fclose($this->skippedCsvHandle);
        }

        $this->skippedCsvHandle = null;
    }

    private function assertWellFormedXmlFile(string $absolutePath): void
    {
        $reader = new \XMLReader;
        $opened = @$reader->open($absolutePath);
        if ($opened !== true) {
            File::delete($absolutePath);
            throw new RuntimeException('Generated Pigu offers feed is not well-formed XML.');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            while ($reader->read()) {
                // Stream-parse only — avoid loading the full document into memory.
            }

            if (libxml_get_errors() !== []) {
                File::delete($absolutePath);
                throw new RuntimeException('Generated Pigu offers feed is not well-formed XML.');
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @param  array<string, mixed>  $progress
     */
    private function reportProgress(array $progress): void
    {
        if ($this->progressCallback === null) {
            return;
        }

        ($this->progressCallback)($progress);
    }

    private function decimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function cdata(string $value): string
    {
        return '<![CDATA['.str_replace(']]>', ']]]]><![CDATA[>', $value).']]>';
    }
}
