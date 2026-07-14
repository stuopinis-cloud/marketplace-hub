<?php

namespace App\Services\Automation;

use App\Services\Marketplace\Varle\VarleFeedPublisher;
use App\Services\Marketplace\Varle\VarleReadinessService;
use App\Services\Shopify\ShopifyProductImporter;
use App\Services\Suppliers\SupplierSyncManager;
use App\Services\Sync\SyncJobFailedCsvExporter;
use Throwable;

class DailyMarketplaceSync
{
    public function __construct(
        private readonly ShopifyProductImporter $shopifyImporter,
        private readonly SupplierSyncManager $supplierSyncManager,
        private readonly VarleReadinessService $readinessService,
        private readonly VarleFeedPublisher $varleFeedPublisher,
        private readonly SyncJobFailedCsvExporter $failedCsvExporter,
    ) {}

    public function run(
        bool $runShopifyImport = true,
        bool $runSupplierSync = true,
        bool $runReadinessRefresh = true,
        bool $runVarleExport = true,
        bool $generateFailedCsv = true,
    ): DailyMarketplaceSyncResult {
        $summary = [];

        try {
            if ($runShopifyImport) {
                $importResult = $this->shopifyImporter->import();
                $summary['shopify_import'] = [
                    'sync_job_id' => $importResult->syncJobId,
                    'products_imported' => $importResult->productsImported,
                    'variants_imported' => $importResult->variantsImported,
                    'failed_items' => $importResult->failedItems,
                ];

                if ($importResult->failedItems > 0) {
                    return DailyMarketplaceSyncResult::failed(
                        'Shopify import finished with failed items.',
                        $summary,
                    );
                }
            }

            if ($runSupplierSync) {
                $supplierResults = $this->supplierSyncManager->syncPublicationSuppliers();
                $summary['supplier_sync'] = $supplierResults;

                $failedSuppliers = collect([
                    isset($supplierResults['mtac']['error']) ? 'M-Tac' : null,
                    isset($supplierResults['helik']['error']) ? 'Helikon' : null,
                    ...collect($supplierResults['csv'] ?? [])
                        ->filter(fn (array $row): bool => isset($row['error']))
                        ->pluck('name')
                        ->all(),
                ])->filter()->values()->all();

                if ($failedSuppliers !== []) {
                    $summary['supplier_sync_warnings'] = $failedSuppliers;
                }
            }

            if ($runReadinessRefresh) {
                $summary['readiness_refresh'] = [
                    'products_refreshed' => $this->readinessService->refreshAll(),
                ];
            }

            if ($runVarleExport) {
                $exportResult = $this->varleFeedPublisher->publish();
                $summary['varle_export'] = [
                    'sync_job_id' => $exportResult->syncJobId,
                    'exported_variants' => $exportResult->exportedVariants,
                    'skipped_variants' => $exportResult->skippedVariants,
                    'feed_path' => $exportResult->feedPath,
                    'public_url' => $exportResult->publicUrl,
                    'published_atomically' => true,
                ];

                if ($exportResult->skippedVariants > 0) {
                    return DailyMarketplaceSyncResult::failed(
                        'Varle export finished with skipped variants.',
                        $summary,
                    );
                }
            }

            if ($generateFailedCsv) {
                $syncJob = $this->failedCsvExporter->resolveSyncJob(null);

                if ($syncJob !== null) {
                    $relativePath = $this->failedCsvExporter->export($syncJob);
                    $summary['failed_csv'] = [
                        'sync_job_id' => $syncJob->id,
                        'path' => $relativePath,
                        'url' => $this->failedCsvExporter->publicUrl($relativePath),
                    ];
                }
            }
        } catch (Throwable $exception) {
            return DailyMarketplaceSyncResult::failed($exception->getMessage(), $summary);
        }

        return DailyMarketplaceSyncResult::success($summary);
    }
}
