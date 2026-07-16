<?php

namespace App\Services\Sync;

use App\Enums\SyncJobStatus;
use App\Jobs\GenerateVarleXmlJob;
use App\Jobs\ImportShopifyProductsJob;
use App\Jobs\RunDailyMarketplaceSyncJob;
use App\Jobs\SyncSupplierStockJob;
use App\Models\SyncJob;
use App\Services\Marketplace\Varle\VarleReadinessRefreshService;

class MarketplaceJobDispatcher
{
    public function __construct(
        private readonly VarleReadinessRefreshService $readinessRefreshService,
    ) {}

    public function dispatchShopifyImport(): JobDispatchResult
    {
        if (MarketplaceJobLock::isLocked(MarketplaceJobLock::SHOPIFY_IMPORT)
            || MarketplaceJobLock::hasActiveJob('import', 'shopify')) {
            return JobDispatchResult::alreadyRunning(
                message: 'Shopify import is already running.',
            );
        }

        ImportShopifyProductsJob::dispatch();

        return JobDispatchResult::dispatched(message: 'Shopify import queued.');
    }

    public function dispatchVarleExport(bool $debug = false): JobDispatchResult
    {
        if (MarketplaceJobLock::isLocked(MarketplaceJobLock::VARLE_EXPORT)
            || MarketplaceJobLock::hasActiveJob('export', channel: 'varle')) {
            return JobDispatchResult::alreadyRunning(
                message: 'Varle export is already running.',
            );
        }

        GenerateVarleXmlJob::dispatch($debug);

        return JobDispatchResult::dispatched(message: 'Varle export queued.');
    }

    public function dispatchReadinessRefresh(?array $productIds = null, int $chunkSize = 100): JobDispatchResult
    {
        $result = $this->readinessRefreshService->dispatch($productIds, $chunkSize);

        if ($result->alreadyRunning) {
            return JobDispatchResult::alreadyRunning(
                syncJob: $result->syncJob,
                message: $result->message ?? 'Varle readiness refresh is already running.',
            );
        }

        return JobDispatchResult::dispatched(
            syncJob: $result->syncJob,
            message: 'Varle readiness refresh queued.',
        );
    }

    public function dispatchSupplierSync(string $supplierCode, bool $dryRun = false): JobDispatchResult
    {
        $lockKey = MarketplaceJobLock::forSupplier($supplierCode);

        if (MarketplaceJobLock::isLocked($lockKey)
            || MarketplaceJobLock::hasActiveJob('import', mb_strtolower($supplierCode))) {
            return JobDispatchResult::alreadyRunning(
                message: "Supplier sync for {$supplierCode} is already running.",
            );
        }

        SyncSupplierStockJob::dispatch($supplierCode, $dryRun);

        return JobDispatchResult::dispatched(
            message: $dryRun ? 'Supplier dry run queued.' : 'Supplier sync queued.',
        );
    }

    public function dispatchDailySync(
        bool $runShopifyImport = true,
        bool $runSupplierSync = true,
        bool $runReadinessRefresh = true,
        bool $runVarleExport = true,
        bool $generateFailedCsv = true,
    ): JobDispatchResult {
        if (MarketplaceJobLock::isLocked(MarketplaceJobLock::MARKETPLACE_DAILY_SYNC)
            || MarketplaceJobLock::hasActiveJob('daily_sync', 'marketplace')) {
            return JobDispatchResult::alreadyRunning(
                message: 'Daily marketplace sync is already running.',
            );
        }

        $syncJob = SyncJob::query()->create([
            'type' => 'daily_sync',
            'source' => 'marketplace',
            'status' => SyncJobStatus::Pending,
            'context' => [
                'stage' => 'queued',
                'run_shopify_import' => $runShopifyImport,
                'run_supplier_sync' => $runSupplierSync,
                'run_readiness_refresh' => $runReadinessRefresh,
                'run_varle_export' => $runVarleExport,
                'generate_failed_csv' => $generateFailedCsv,
            ],
        ]);

        RunDailyMarketplaceSyncJob::dispatch(
            $syncJob->id,
            $runShopifyImport,
            $runSupplierSync,
            $runReadinessRefresh,
            $runVarleExport,
            $generateFailedCsv,
        );

        return JobDispatchResult::dispatched(
            syncJob: $syncJob,
            message: 'Daily marketplace sync queued.',
        );
    }
}
