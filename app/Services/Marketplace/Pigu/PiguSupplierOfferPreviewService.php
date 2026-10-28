<?php

namespace App\Services\Marketplace\Pigu;

use App\Models\Supplier;
use App\Models\SupplierProduct;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Isolated dry-run of a supplier's contribution to the combined Pigu offers feed.
 * Never writes production feeds, FeedFile rows, or supplier activation flags.
 */
class PiguSupplierOfferPreviewService
{
    public function __construct(
        private readonly PiguOfferFeedExporter $exporter,
    ) {}

    /**
     * @return array{
     *     supplier_id: int,
     *     supplier_name: string,
     *     pigu_enabled_saved: bool,
     *     feed_path: string,
     *     absolute_path: string,
     *     skipped_csv_path: string,
     *     skipped_csv_absolute_path: string,
     *     disk: string,
     *     total_supplier_products: int,
     *     valid_offers: int,
     *     shopify_matched_offers: int,
     *     supplier_only_offers: int,
     *     deduplicated_offers: int,
     *     skipped_by_reason: array<string, int>,
     *     examples: list<array{
     *         sku: string,
     *         ean: string,
     *         price: float|int|string|null,
     *         stock: int|null,
     *         collectionhours: int|null,
     *         hours_source: string|null
     *     }>,
     *     export: array<string, mixed>
     * }
     */
    public function generate(Supplier $supplier, bool $allValid = true): array
    {
        $enabledBefore = PiguSupplierOfferConfig::isEnabled($supplier);

        $result = $this->exporter->export(PiguExportOptions::supplierPreview($supplier->id, $allValid));

        $supplier->refresh();
        if (PiguSupplierOfferConfig::isEnabled($supplier) !== $enabledBefore) {
            throw new RuntimeException('Preview must not mutate supplier Pigu activation state.');
        }

        if (($result['disk'] ?? null) !== PiguOfferFeedExporter::PREVIEW_DISK) {
            throw new RuntimeException('Preview wrote to an unexpected storage disk.');
        }

        if (str_contains((string) $result['feed_path'], PiguOfferFeedExporter::PRODUCTION_FEED_RELATIVE)) {
            throw new RuntimeException('Preview must not write the production pigu-offers.xml path.');
        }

        $contribution = array_values(array_filter(
            $result['exported_rows'] ?? [],
            function (array $row) use ($supplier): bool {
                if ((int) ($row['supplier_id'] ?? 0) !== (int) $supplier->id) {
                    return false;
                }

                return in_array($row['hours_source'] ?? null, [
                    PiguOfferFeedExporter::HOURS_SOURCE_SUPPLIER,
                    PiguOfferFeedExporter::HOURS_SOURCE_SUPPLIER_ONLY,
                ], true);
            },
        ));

        $supplierSkipped = array_values(array_filter(
            $result['skipped_rows'] ?? [],
            fn (array $row): bool => (int) ($row['supplier_id'] ?? 0) === (int) $supplier->id,
        ));

        $skippedByReason = [];
        $deduplicated = 0;
        foreach ($supplierSkipped as $row) {
            $code = (string) ($row['skip_reason'] ?? 'unknown');
            $skippedByReason[$code] = ($skippedByReason[$code] ?? 0) + 1;
            if (in_array($code, ['duplicate_ean', 'duplicate_sku'], true)) {
                $deduplicated++;
            }
        }
        ksort($skippedByReason);

        $shopifyMatched = count(array_filter(
            $contribution,
            fn (array $row): bool => ($row['hours_source'] ?? null) === PiguOfferFeedExporter::HOURS_SOURCE_SUPPLIER,
        ));
        $supplierOnly = count(array_filter(
            $contribution,
            fn (array $row): bool => ($row['hours_source'] ?? null) === PiguOfferFeedExporter::HOURS_SOURCE_SUPPLIER_ONLY,
        ));

        $examples = array_map(
            fn (array $row): array => [
                'sku' => (string) ($row['sku'] ?? ''),
                'ean' => (string) ($row['ean'] ?? ''),
                'price' => $row['price_after'] ?? $row['price_before'] ?? null,
                'stock' => isset($row['stock']) ? (int) $row['stock'] : null,
                'collectionhours' => isset($row['collection_hours']) ? (int) $row['collection_hours'] : null,
                'hours_source' => $row['hours_source'] ?? null,
            ],
            array_slice($contribution, 0, 8),
        );

        $skippedCsvPath = (string) $result['failed_csv_path'];

        return [
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'pigu_enabled_saved' => $enabledBefore,
            'feed_path' => (string) $result['feed_path'],
            'absolute_path' => (string) $result['absolute_path'],
            'skipped_csv_path' => $skippedCsvPath,
            'skipped_csv_absolute_path' => Storage::disk(PiguOfferFeedExporter::PREVIEW_DISK)->path($skippedCsvPath),
            'disk' => PiguOfferFeedExporter::PREVIEW_DISK,
            'total_supplier_products' => SupplierProduct::query()
                ->where('supplier_id', $supplier->id)
                ->where('enabled', true)
                ->count(),
            'valid_offers' => count($contribution),
            'shopify_matched_offers' => $shopifyMatched,
            'supplier_only_offers' => $supplierOnly,
            'deduplicated_offers' => $deduplicated,
            'skipped_by_reason' => $skippedByReason,
            'examples' => $examples,
            'export' => [
                'offers_exported' => $result['offers_exported'],
                'offers_skipped' => $result['offers_skipped'],
                'variants_considered' => $result['variants_considered'],
                'supplier_only_considered' => $result['supplier_only_considered'] ?? 0,
            ],
        ];
    }

    public function ensurePreviewFiles(Supplier $supplier): array
    {
        $dir = PiguOfferFeedExporter::previewDirectory($supplier->id);
        $disk = Storage::disk(PiguOfferFeedExporter::PREVIEW_DISK);
        $xmlPath = $dir.'/offers.xml';
        $csvPath = $dir.'/skipped.csv';

        if (! $disk->exists($xmlPath) || ! $disk->exists($csvPath)) {
            return $this->generate($supplier);
        }

        return [
            'feed_path' => $xmlPath,
            'absolute_path' => $disk->path($xmlPath),
            'skipped_csv_path' => $csvPath,
            'skipped_csv_absolute_path' => $disk->path($csvPath),
            'disk' => PiguOfferFeedExporter::PREVIEW_DISK,
            'supplier_id' => $supplier->id,
        ];
    }
}
