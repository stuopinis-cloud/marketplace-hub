<?php

namespace App\Services\Suppliers\Json;

use App\Models\Supplier;
use App\Services\Suppliers\SupplierStockSyncOrchestrator;
use App\Services\Suppliers\SupplierSyncOptions;
use App\Services\Suppliers\SupplierSyncResult;
use RuntimeException;

class SupplierJsonSupplierImporter
{
    public function __construct(
        private readonly SupplierJsonFeedClient $feedClient,
        private readonly SupplierJsonParser $parser,
        private readonly SupplierStockSyncOrchestrator $orchestrator,
    ) {}

    public function sync(Supplier $supplier, ?SupplierSyncOptions $options = null): SupplierSyncResult
    {
        $options ??= new SupplierSyncOptions;

        if (! in_array($supplier->connector_type, [
            Supplier::CONNECTOR_JSON_API,
            Supplier::CONNECTOR_API,
        ], true)) {
            throw new RuntimeException('Supplier connector is not configured for JSON/API import.');
        }

        if (! SupplierJsonConfig::isConfigured($supplier)) {
            throw new RuntimeException('JSON data path and SKU path mapping are required.');
        }

        $json = $this->feedClient->fetch($supplier);
        $parsed = $this->parser->parse($json, $supplier);

        return $this->orchestrator->sync(
            supplier: $supplier,
            syncJobSource: 'supplier:json:'.(string) $supplier->code,
            vendorScope: SupplierJsonConfig::vendorScope($supplier),
            entries: $parsed['entries'],
            options: $options,
            ambiguousMatchMessage: 'Multiple Shopify variants share the same SKU for this JSON supplier scope.',
            preSkippedRows: $parsed['skipped'],
        );
    }

    /**
     * @return array{
     *     top_level_keys: array<int, string>,
     *     sample_rows: array<int, array<string, mixed>>,
     *     entry_count: int,
     *     skipped_count: int
     * }
     */
    public function preview(Supplier $supplier, int $limit = 10): array
    {
        $json = $this->feedClient->fetch($supplier);
        $parsed = $this->parser->parse($json, $supplier);

        return [
            'top_level_keys' => $parsed['top_level_keys'],
            'sample_rows' => array_slice($parsed['sample_rows'], 0, $limit),
            'entry_count' => count($parsed['entries']),
            'skipped_count' => count($parsed['skipped']),
        ];
    }
}
