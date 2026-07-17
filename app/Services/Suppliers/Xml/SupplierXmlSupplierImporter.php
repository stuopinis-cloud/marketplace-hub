<?php

namespace App\Services\Suppliers\Xml;

use App\Models\Supplier;
use App\Services\Suppliers\SupplierStockSyncOrchestrator;
use App\Services\Suppliers\SupplierSyncOptions;
use App\Services\Suppliers\SupplierSyncResult;
use RuntimeException;

class SupplierXmlSupplierImporter
{
    public function __construct(
        private readonly SupplierXmlFeedClient $feedClient,
        private readonly SupplierXmlParser $parser,
        private readonly SupplierStockSyncOrchestrator $orchestrator,
    ) {}

    public function sync(Supplier $supplier, ?SupplierSyncOptions $options = null): SupplierSyncResult
    {
        $options ??= new SupplierSyncOptions;

        if ($supplier->connector_type !== Supplier::CONNECTOR_XML_URL) {
            throw new RuntimeException('Supplier connector is not configured for XML import.');
        }

        if (blank($supplier->endpoint_url)) {
            throw new RuntimeException('XML supplier endpoint URL is not configured.');
        }

        if (! SupplierXmlConfig::isConfigured($supplier)) {
            throw new RuntimeException('XML item path and SKU path mapping are required.');
        }

        $xml = $this->feedClient->fetch((string) $supplier->endpoint_url);
        $parsed = $this->parser->parse($xml, $supplier);

        return $this->orchestrator->sync(
            supplier: $supplier,
            syncJobSource: 'supplier:xml:'.(string) $supplier->code,
            vendorScope: SupplierXmlConfig::vendorScope($supplier),
            entries: $parsed['entries'],
            options: $options,
            ambiguousMatchMessage: 'Multiple Shopify variants share the same SKU for this XML supplier scope.',
            preSkippedRows: $parsed['skipped'],
        );
    }
}
