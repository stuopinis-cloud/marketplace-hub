<?php

namespace App\Services\Automation;

use App\Services\Marketplace\Translations\TranslationQueueService;
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
        private readonly TranslationQueueService $translationQueue = new TranslationQueueService,
    ) {}

    public function run(
        bool $runShopifyImport = true,
        bool $runSupplierSync = true,
        bool $runReadinessRefresh = true,
        bool $runVarleExport = true,
        bool $generateFailedCsv = true,
    ): DailyMarketplaceSyncResult {
        $summary = [];
        $warnings = [];

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

                if (config('marketplace.translations.auto_queue_missing_translations_for_ebay', false)) {
                    try {
                        $queued = $this->translationQueue->queueMissingForMarketplace('ebay', 'en');
                        $summary['ebay_translations_queued'] = $queued;
                    } catch (Throwable $exception) {
                        $summary['ebay_translations_queued'] = [
                            'error' => $exception->getMessage(),
                        ];
                        $warnings[] = 'Failed to queue eBay translations: '.$exception->getMessage();
                    }
                }
            }

            if ($runSupplierSync) {
                $supplierResults = $this->supplierSyncManager->syncPublicationSuppliers();
                $summary['supplier_sync'] = $supplierResults;

                $failedSuppliers = collect($supplierResults)
                    ->filter(fn (array $row): bool => isset($row['error']))
                    ->values();

                if ($failedSuppliers->isNotEmpty()) {
                    $names = $failedSuppliers->pluck('name')->all();
                    $summary['supplier_sync_warnings'] = $names;
                    $warnings[] = 'Supplier sync completed with warnings: '.implode(', ', $names);
                }

                $blockingFailures = $failedSuppliers
                    ->filter(fn (array $row): bool => (bool) ($row['blocked'] ?? false))
                    ->values();

                if ($blockingFailures->isNotEmpty()) {
                    $names = $blockingFailures->pluck('name')->all();

                    return DailyMarketplaceSyncResult::failed(
                        'Daily marketplace sync blocked by supplier failure: '.implode(', ', $names),
                        $summary,
                    );
                }
            }

            if ($runReadinessRefresh) {
                $summary['readiness_refresh'] = [
                    'products_refreshed' => $this->readinessService->refreshAll(),
                ];
            }

            $varlePartial = false;

            if ($runVarleExport) {
                $exportResult = $this->varleFeedPublisher->publish();
                $summary['varle_export'] = [
                    'sync_job_id' => $exportResult->syncJobId,
                    'exported_variants' => $exportResult->exportedVariants,
                    'skipped_variants' => $exportResult->skippedVariants,
                    'feed_path' => $exportResult->feedPath,
                    'public_url' => $exportResult->publicUrl,
                    'published_atomically' => true,
                    'status' => $exportResult->skippedVariants > 0 && $exportResult->exportedVariants > 0
                        ? 'partial'
                        : ($exportResult->exportedVariants > 0 ? 'completed' : ($exportResult->skippedVariants > 0 ? 'failed' : 'completed')),
                ];

                if ($exportResult->exportedVariants === 0 && $exportResult->skippedVariants > 0) {
                    return DailyMarketplaceSyncResult::failed(
                        'Varle export failed: no variants were exported.',
                        $summary,
                    );
                }

                if ($exportResult->skippedVariants > 0) {
                    $varlePartial = true;
                    $warning = 'Varle export finished with skipped variants.';
                    $warnings[] = $warning;
                    $summary['varle_export']['warning'] = $warning;
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

        if ($varlePartial) {
            return DailyMarketplaceSyncResult::partial(
                'Daily marketplace sync completed with Varle export warnings.',
                $summary,
                $warnings,
            );
        }

        return DailyMarketplaceSyncResult::success($summary, warnings: $warnings);
    }
}
