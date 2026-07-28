<?php

namespace App\Services\Marketplace\Pigu;

use App\Models\MarketplaceChannel;
use App\Models\Product;
use App\Models\ProductVariant;
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
    public function __construct(
        private readonly PiguProductFeedExporter $products,
        private readonly PiguReadinessService $readiness,
    ) {}

    /**
     * @return array{
     *     channel_id: int,
     *     feed_path: string,
     *     absolute_path: string,
     *     public_url: string,
     *     offers_exported: int,
     *     offers_skipped: int,
     *     skipped_by_code: array<string, int>
     * }
     */
    public function export(?PiguExportOptions $options = null): array
    {
        $options ??= new PiguExportOptions;
        $channel = $this->products->resolveChannel();
        $config = $this->products->channelConfig($channel);

        $relativePath = (string) ($config['offers_feed_path'] ?? 'feeds/pigu-offers.xml');
        $tempPath = (string) ($config['offers_feed_temp_path'] ?? 'feeds/pigu-offers.xml.tmp');
        $collectionHours = (int) ($config['collection_hours'] ?? 24);

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

        $handle = fopen($tempAbsolute, 'w');
        if ($handle === false) {
            throw new RuntimeException('Unable to open Pigu offers feed temp file for writing.');
        }

        try {
            fwrite($handle, '<?xml version="1.0" encoding="UTF-8"?>'."\n");
            fwrite($handle, "<products>\n");

            $query = Product::query()
                ->with(['variants.inventoryLevels', 'sourceCategories'])
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
                $collectionHours,
                &$exported,
                &$skipped,
                &$skippedByCode,
            ): void {
                foreach ($products as $product) {
                    $readiness = $this->readiness->analyzeProduct($product, $channel, $easyEligibleHandles);

                    if (! $readiness['exportable']) {
                        $skipped++;
                        $skippedByCode['not_exportable'] = ($skippedByCode['not_exportable'] ?? 0) + 1;

                        continue;
                    }

                    foreach ($product->variants as $variant) {
                        $row = $this->buildOfferRow($product, $variant, $collectionHours);

                        if ($row === null) {
                            $skipped++;
                            $skippedByCode['invalid_offer'] = ($skippedByCode['invalid_offer'] ?? 0) + 1;

                            continue;
                        }

                        fwrite($handle, $row);
                        $exported++;
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

        return [
            'channel_id' => $channel->id,
            'feed_path' => $relativePath,
            'absolute_path' => storage_path('app/public/'.$relativePath),
            'public_url' => $publicUrl,
            'offers_exported' => $exported,
            'offers_skipped' => $skipped,
            'skipped_by_code' => $skippedByCode,
        ];
    }

    private function buildOfferRow(Product $product, ProductVariant $variant, int $collectionHours): ?string
    {
        $availability = $this->readiness->variantAvailability($variant);
        $sku = trim((string) ($variant->sku ?? ''));
        $barcode = preg_replace('/\D+/', '', (string) ($variant->barcode ?? '')) ?? '';
        $price = $variant->price !== null ? (float) $variant->price : null;
        $compare = $variant->compare_at_price !== null ? (float) $variant->compare_at_price : null;

        if ($sku === '' || $barcode === '' || ! preg_match('/^\d{11,13}$/', $barcode) || $price === null || $price <= 0) {
            return null;
        }

        $before = $compare !== null && $compare > $price ? $compare : $price;
        $after = $price;
        $stock = max(0, (int) $availability['quantity']);

        $xml = "  <product>\n";
        $xml .= '    <sku>'.$this->cdata($sku)."</sku>\n";
        $xml .= '    <ean>'.$this->cdata($barcode)."</ean>\n";
        $xml .= '    <price-before-discount>'.$this->decimal($before)."</price-before-discount>\n";
        $xml .= '    <price-after-discount>'.$this->decimal($after)."</price-after-discount>\n";
        $xml .= '    <stock>'.$stock."</stock>\n";
        $xml .= '    <collectionhours>'.$collectionHours."</collectionhours>\n";
        $xml .= "  </product>\n";

        return $xml;
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
