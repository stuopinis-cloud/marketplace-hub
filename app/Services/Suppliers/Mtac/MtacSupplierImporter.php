<?php

namespace App\Services\Suppliers\Mtac;

use App\Services\Suppliers\SupplierProvisioner;
use App\Services\Suppliers\SupplierStockSyncOrchestrator;
use App\Services\Suppliers\SupplierSyncOptions;
use App\Services\Suppliers\SupplierSyncResult;
use RuntimeException;

class MtacSupplierImporter
{
    public function __construct(
        private readonly SupplierProvisioner $supplierProvisioner,
        private readonly MtacFeedClient $feedClient,
        private readonly MtacXmlParser $xmlParser,
        private readonly SupplierStockSyncOrchestrator $orchestrator,
    ) {}

    public function sync(?SupplierSyncOptions $options = null): SupplierSyncResult
    {
        $options ??= new SupplierSyncOptions;
        $supplier = $this->supplierProvisioner->ensureMtacSupplier();

        if (blank($supplier->endpoint_url)) {
            throw new RuntimeException('M-Tac supplier endpoint URL is not configured.');
        }

        $xml = $this->feedClient->fetch((string) $supplier->endpoint_url);
        $entries = $this->xmlParser->parse($xml);

        return $this->orchestrator->sync(
            supplier: $supplier,
            syncJobSource: 'supplier:mtac',
            vendorScope: [MtacSkuMatcher::VENDOR],
            entries: $entries,
            options: $options,
            ambiguousMatchMessage: 'Multiple Shopify variants share the same SKU for M-Tac vendor.',
        );
    }
}
