<?php

namespace App\Services\Marketplace\Pigu;

use App\Enums\FeedFileStatus;
use App\Models\Product;
use App\Models\ProductVariant;
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

    public const string HOURS_SOURCE_BACKORDER = 'backorder';

    public const string FAILED_CSV_PATH = 'exports/pigu_offers_failed_latest.csv';

    /** @var array<string, int> */
    private array $seenEans = [];

    /** @var array<string, true> */
    private array $seenSkus = [];

    /** @var list<array<string, mixed>> */
    private array $skippedRows = [];

    private int $consideredVariants = 0;

    private int $duplicateEanCount = 0;

    public function __construct(
        private readonly PiguProductFeedExporter $products,
        private readonly PiguReadinessService $readiness,
        private readonly ProductAvailabilityResolver $availability,
    ) {}

    /**
     * @return array{
     *     channel_id: int,
     *     feed_path: string,
     *     absolute_path: string,
     *     public_url: string,
     *     filter: array{easy_only: bool, all_valid: bool},
     *     variants_considered: int,
     *     offers_exported: int,
     *     offers_skipped: int,
     *     skipped_by_code: array<string, int>,
     *     offers_by_collectionhours: array<string, int>,
     *     local_offers_count: int,
     *     supplier_offers_count: int,
     *     backorder_offers_count: int,
     *     unique_sku_count: int,
     *     unique_ean_count: int,
     *     duplicate_ean_count: int,
     *     failed_csv_path: string
     * }
     */
    public function export(?PiguExportOptions $options = null): array
    {
        $options ??= new PiguExportOptions;
        $this->seenEans = [];
        $this->seenSkus = [];
        $this->skippedRows = [];
        $this->consideredVariants = 0;
        $this->duplicateEanCount = 0;

        $channel = $this->products->resolveChannel();
        $config = $this->products->channelConfig($channel);

        $relativePath = (string) ($config['offers_feed_path'] ?? 'feeds/pigu-offers.xml');
        $tempPath = (string) ($config['offers_feed_temp_path'] ?? 'feeds/pigu-offers.xml.tmp');

        $easyEligibleHandles = (! $options->allValid && $options->easyOnly)
            ? $this->products->easyOnlyCollectionHandles($channel)
            : null;

        $disk = Storage::disk('public');
        $disk->makeDirectory('feeds');
        $disk->delete($tempPath);

        $tempAbsolute = $disk->path($tempPath);
        $finalAbsolute = $disk->path($relativePath);

        $exported = 0;
        $skipped = 0;
        $skippedByCode = [];
        $offersByCollectionHours = [];
        $localOffers = 0;
        $supplierOffers = 0;
        $backorderOffers = 0;

        $handle = fopen($tempAbsolute, 'w');
        if ($handle === false) {
            throw new RuntimeException('Unable to open Pigu offers feed temp file for writing.');
        }

        try {
            fwrite($handle, '<?xml version="1.0" encoding="UTF-8"?>'."\n");
            fwrite($handle, "<products>\n");

            $query = Product::query()
                ->with(['variants.inventoryLevels', 'variants.supplierProducts.supplier', 'sourceCategories'])
                ->orderBy('id');

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

            $query->chunkById((int) ($config['export_chunk_size'] ?? 100), function ($products) use (
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

                        fwrite($handle, $built['xml']);
                        $exported++;

                        $sku = (string) $built['sku'];
                        $ean = (string) $built['ean'];
                        $this->seenSkus[$sku] = true;
                        $this->seenEans[$ean] = $variant->id;

                        $hoursKey = (string) $built['collection_hours'];
                        $offersByCollectionHours[$hoursKey] = ($offersByCollectionHours[$hoursKey] ?? 0) + 1;

                        match ($built['hours_source']) {
                            self::HOURS_SOURCE_LOCAL => $localOffers++,
                            self::HOURS_SOURCE_SUPPLIER => $supplierOffers++,
                            self::HOURS_SOURCE_BACKORDER => $backorderOffers++,
                            default => null,
                        };
                    }
                }
            });

            fwrite($handle, "</products>\n");
        } finally {
            fclose($handle);
        }

        $xml = file_get_contents($tempAbsolute);
        if ($xml === false || @simplexml_load_string($xml) === false) {
            File::delete($tempAbsolute);
            throw new RuntimeException('Generated Pigu offers feed is not well-formed XML.');
        }

        File::ensureDirectoryExists(dirname($finalAbsolute), 0755);
        File::replace($finalAbsolute, $xml);
        File::delete($tempAbsolute);

        $this->writeSkippedCsv($config);

        $offersFilename = 'pigu-offers.xml';
        $offersPath = 'feeds/pigu-offers.xml';
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

        ksort($offersByCollectionHours, SORT_NUMERIC);
        ksort($skippedByCode);

        $failedCsvRelative = (string) ($config['offers_failed_csv_path'] ?? self::FAILED_CSV_PATH);

        return [
            'channel_id' => $channel->id,
            'feed_path' => $relativePath,
            'absolute_path' => storage_path('app/public/'.$relativePath),
            'public_url' => $offersPublicUrl,
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
            'backorder_offers_count' => $backorderOffers,
            'unique_sku_count' => count($this->seenSkus),
            'unique_ean_count' => count($this->seenEans),
            'duplicate_ean_count' => $this->duplicateEanCount,
            'failed_csv_path' => $failedCsvRelative,
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
            'collection_hours' => $hours['hours'],
            'hours_source' => $hours['source'],
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
            return ['hours' => $supplierHours, 'source' => self::HOURS_SOURCE_SUPPLIER];
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
        $this->skippedRows[] = [
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
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function writeSkippedCsv(array $config): void
    {
        $relative = (string) ($config['offers_failed_csv_path'] ?? self::FAILED_CSV_PATH);
        $disk = Storage::disk('public');
        $disk->makeDirectory(dirname($relative));

        $handle = fopen($disk->path($relative), 'w');
        if ($handle === false) {
            return;
        }

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
        ]);

        foreach ($this->skippedRows as $row) {
            fputcsv($handle, [
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
            ]);
        }

        fclose($handle);
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
