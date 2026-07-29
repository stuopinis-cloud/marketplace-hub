<?php

namespace App\Services\Marketplace\Pigu;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Marketplace\ProductAvailabilityResolver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Separate PHH "stock and prices" feed.
 * Product catalog XML must not include prices; this feed matches modifications by sku/ean.
 *
 * Schema (single-market discount mechanism docs):
 * sku, ean, price-before-discount, price-after-discount, stock, collectionhours
 */
class PiguOfferFeedExporter
{
    public const string HOURS_SOURCE_LOCAL = 'local';

    public const string HOURS_SOURCE_SUPPLIER = 'supplier';

    public const string HOURS_SOURCE_BACKORDER = 'backorder';

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
     *     offers_exported: int,
     *     offers_skipped: int,
     *     skipped_by_code: array<string, int>,
     *     offers_by_collectionhours: array<string, int>,
     *     local_offers_count: int,
     *     supplier_offers_count: int,
     *     backorder_offers_count: int
     * }
     */
    public function export(?PiguExportOptions $options = null): array
    {
        $options ??= new PiguExportOptions;
        $channel = $this->products->resolveChannel();
        $config = $this->products->channelConfig($channel);

        $relativePath = (string) ($config['offers_feed_path'] ?? 'feeds/pigu-offers.xml');
        $tempPath = (string) ($config['offers_feed_temp_path'] ?? 'feeds/pigu-offers.xml.tmp');

        $easyEligibleHandles = $options->easyOnly
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

            if ($options->easyOnly) {
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
                    $readiness = $this->readiness->analyzeProduct($product, $channel, $easyEligibleHandles);

                    if (! $readiness['exportable']) {
                        $skipped++;
                        $skippedByCode['not_exportable'] = ($skippedByCode['not_exportable'] ?? 0) + 1;

                        continue;
                    }

                    foreach ($product->variants as $variant) {
                        $built = $this->buildOfferRow($variant, $config);

                        if ($built === null) {
                            $skipped++;
                            $skippedByCode['invalid_offer'] = ($skippedByCode['invalid_offer'] ?? 0) + 1;

                            continue;
                        }

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

        $publicUrl = (string) (
            $config['offers_public_url']
            ?? url('/feeds/'.basename($relativePath))
        );

        ksort($offersByCollectionHours, SORT_NUMERIC);

        return [
            'channel_id' => $channel->id,
            'feed_path' => $relativePath,
            'absolute_path' => storage_path('app/public/'.$relativePath),
            'public_url' => $publicUrl,
            'offers_exported' => $exported,
            'offers_skipped' => $skipped,
            'skipped_by_code' => $skippedByCode,
            'offers_by_collectionhours' => $offersByCollectionHours,
            'local_offers_count' => $localOffers,
            'supplier_offers_count' => $supplierOffers,
            'backorder_offers_count' => $backorderOffers,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{xml: string, collection_hours: int, hours_source: string}|null
     */
    private function buildOfferRow(ProductVariant $variant, array $config): ?array
    {
        $resolved = $this->availability->resolve($variant);
        $sku = trim((string) ($variant->sku ?? ''));
        $barcode = preg_replace('/\D+/', '', (string) ($variant->barcode ?? '')) ?? '';
        $price = $variant->price !== null ? (float) $variant->price : null;
        $compare = $variant->compare_at_price !== null ? (float) $variant->compare_at_price : null;

        if ($sku === '' || $barcode === '' || ! preg_match('/^\d{11,13}$/', $barcode) || $price === null || $price <= 0) {
            return null;
        }

        $stock = max(0, (int) $resolved['quantity']);
        $hours = $this->resolveCollectionHours($resolved, $config);

        // Skip when there is no availability source (out of stock, not backorder).
        if ($hours === null || $hours['hours'] <= 0) {
            return null;
        }

        $before = $compare !== null && $compare > $price ? $compare : $price;
        $after = $price;

        $xml = "  <product>\n";
        $xml .= '    <sku>'.$this->cdata($sku)."</sku>\n";
        $xml .= '    <ean>'.$this->cdata($barcode)."</ean>\n";
        $xml .= '    <price-before-discount>'.$this->decimal($before)."</price-before-discount>\n";
        $xml .= '    <price-after-discount>'.$this->decimal($after)."</price-after-discount>\n";
        $xml .= '    <stock>'.$stock."</stock>\n";
        $xml .= '    <collectionhours>'.$hours['hours']."</collectionhours>\n";
        $xml .= "  </product>\n";

        return [
            'xml' => $xml,
            'collection_hours' => $hours['hours'],
            'hours_source' => $hours['source'],
        ];
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

    private function decimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function cdata(string $value): string
    {
        return '<![CDATA['.str_replace(']]>', ']]]]><![CDATA[>', $value).']]>';
    }
}
