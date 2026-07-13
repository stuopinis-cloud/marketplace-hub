<?php

namespace App\Services\Suppliers\Csv;

use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Suppliers\SupplierStockSyncOrchestrator;
use App\Services\Suppliers\SupplierSyncOptions;
use App\Services\Suppliers\SupplierSyncResult;
use RuntimeException;

class SupplierCsvSupplierImporter
{
    public function __construct(
        private readonly SupplierCsvFeedClient $feedClient,
        private readonly SupplierCsvParser $parser,
        private readonly SupplierStockSyncOrchestrator $orchestrator,
    ) {}

    public function sync(Supplier $supplier, ?SupplierSyncOptions $options = null): SupplierSyncResult
    {
        $options ??= new SupplierSyncOptions;

        if (! in_array($supplier->connector_type, [Supplier::CONNECTOR_CSV_URL, Supplier::CONNECTOR_CSV_UPLOAD], true)) {
            throw new RuntimeException('Supplier connector is not configured for CSV import.');
        }

        if (SupplierCsvConfig::skuColumn($supplier) === null) {
            throw new RuntimeException('CSV SKU column mapping is required.');
        }

        $content = $this->feedClient->fetch($supplier);
        $parsed = $this->parser->parse($content, $supplier);
        $vendorScope = SupplierCsvConfig::vendorScope($supplier);

        return $this->orchestrator->sync(
            supplier: $supplier,
            syncJobSource: 'supplier:csv:'.(string) $supplier->code,
            vendorScope: $vendorScope,
            entries: $parsed['entries'],
            options: $options,
            ambiguousMatchMessage: 'Multiple Shopify variants share the same SKU or barcode for this supplier scope.',
            preSkippedRows: $parsed['skipped'],
        );
    }

    /**
     * @return array{
     *     headers: array<int, string>,
     *     preview_rows: array<int, array<string, mixed>>,
     *     sample_mappings: array<string, array{column: ?string, samples: array<int, string>}>
     * }
     */
    public function preview(Supplier $supplier, int $limit = 20): array
    {
        $content = $this->feedClient->fetch($supplier);
        $parsed = $this->parser->parse($content, $supplier, $limit);

        return [
            'headers' => $parsed['headers'],
            'preview_rows' => $parsed['preview_rows'],
            'sample_mappings' => $this->buildSampleMappings($parsed['preview_rows'], $supplier),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array{column: ?string, samples: array<int, string>}>
     */
    private function buildSampleMappings(array $rows, Supplier $supplier): array
    {
        $fields = [
            'sku' => SupplierCsvConfig::skuColumn($supplier),
            'stock' => SupplierCsvConfig::stockColumn($supplier),
            'availability' => SupplierCsvConfig::availabilityColumn($supplier),
            'barcode' => SupplierCsvConfig::barcodeColumn($supplier),
            'vendor' => SupplierCsvConfig::vendorColumn($supplier),
            'title' => SupplierCsvConfig::titleColumn($supplier),
            'price' => SupplierCsvConfig::priceColumn($supplier),
        ];

        $samples = [];

        foreach ($fields as $field => $column) {
            $values = [];

            if ($column !== null) {
                foreach ($rows as $row) {
                    $value = trim((string) ($row[$column] ?? ''));

                    if ($value !== '') {
                        $values[] = $value;
                    }

                    if (count($values) >= 3) {
                        break;
                    }
                }
            }

            $samples[$field] = [
                'column' => $column,
                'samples' => $values,
            ];
        }

        $hasStockMapping = $fields['stock'] !== null;
        $hasAvailabilityMapping = $fields['availability'] !== null;

        $samples['_warnings'] = [
            'column' => null,
            'samples' => $hasStockMapping || $hasAvailabilityMapping
                ? []
                : ['Map stock quantity or availability for reliable export behavior.'],
        ];

        return $samples;
    }

    public function countAvailabilityFallbackCandidates(array $entries): int
    {
        return collect($entries)->filter(function (array $entry): bool {
            return ($entry['stock_quantity'] ?? null) === null
                && ($entry['availability_status'] ?? null) === SupplierProduct::AVAILABILITY_AVAILABLE;
        })->count();
    }
}
