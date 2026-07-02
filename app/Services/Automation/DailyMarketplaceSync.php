<?php

namespace App\Services\Automation;

use App\Services\Marketplace\Varle\VarleXmlExporter;
use App\Services\Shopify\ShopifyProductImporter;
use App\Services\Sync\SyncJobFailedCsvExporter;
use Throwable;

class DailyMarketplaceSync
{
    public function __construct(
        private readonly ShopifyProductImporter $shopifyImporter,
        private readonly VarleXmlExporter $varleExporter,
        private readonly SyncJobFailedCsvExporter $failedCsvExporter,
    ) {}

    public function run(
        bool $runShopifyImport = true,
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

            if ($runVarleExport) {
                $exportResult = $this->varleExporter->export();
                $summary['varle_export'] = [
                    'sync_job_id' => $exportResult->syncJobId,
                    'exported_variants' => $exportResult->exportedVariants,
                    'skipped_variants' => $exportResult->skippedVariants,
                    'feed_path' => $exportResult->feedPath,
                    'public_url' => $exportResult->publicUrl,
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
