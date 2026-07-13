<?php

namespace App\Services\Suppliers\Helik;

use App\Models\Supplier;
use App\Services\Suppliers\SupplierProvisioner;
use App\Services\Suppliers\SupplierStockSyncOrchestrator;
use App\Services\Suppliers\SupplierSyncOptions;
use App\Services\Suppliers\SupplierSyncResult;
use RuntimeException;

class HelikSupplierImporter
{
    public const array VENDOR_SCOPE = ['Helikon-Tex', 'Direct-Action'];

    public function __construct(
        private readonly SupplierProvisioner $supplierProvisioner,
        private readonly HelikFeedClient $feedClient,
        private readonly HelikResponseParser $responseParser,
        private readonly SupplierStockSyncOrchestrator $orchestrator,
    ) {}

    public function sync(?SupplierSyncOptions $options = null): SupplierSyncResult
    {
        $options ??= new SupplierSyncOptions;
        $supplier = $this->supplierProvisioner->ensureHelikSupplier();

        if (blank($supplier->endpoint_url)) {
            throw new RuntimeException('Helikon supplier endpoint URL is not configured.');
        }

        $json = $this->feedClient->fetch($supplier);
        $dataPath = (string) data_get($supplier->config, 'response_data_path', 'Value');
        $parsed = $this->responseParser->parse($json, $dataPath);

        return $this->orchestrator->sync(
            supplier: $supplier,
            syncJobSource: 'supplier:helik',
            vendorScope: self::VENDOR_SCOPE,
            entries: $parsed['entries'],
            options: $options,
            ambiguousMatchMessage: 'Multiple Shopify variants share the same SKU within Helikon-Tex / Direct-Action vendor scope.',
            preSkippedRows: $parsed['skipped'],
        );
    }
}
