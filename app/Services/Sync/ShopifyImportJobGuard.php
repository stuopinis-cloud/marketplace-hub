<?php

namespace App\Services\Sync;

use App\Enums\SyncJobStatus;
use App\Models\SyncJob;

class ShopifyImportJobGuard
{
    public function __construct(
        private readonly SyncJobProcessInspector $processInspector,
        private readonly SyncJobHealthService $healthService,
        private readonly StuckSyncJobMarker $stuckSyncJobMarker,
    ) {}

    public function findBlockingRunningImport(): ?SyncJob
    {
        return SyncJob::query()
            ->where('type', 'import')
            ->where('source', 'shopify')
            ->where('status', SyncJobStatus::Running)
            ->orderByDesc('id')
            ->get()
            ->first(function (SyncJob $job): bool {
                $health = $this->healthService->assess($job);

                if ($health['health_status'] !== SyncJobHealthService::HEALTH_HEALTHY_RUNNING) {
                    return false;
                }

                return $this->processInspector->isProcessRunning($job->process_id);
            });
    }

    public function markStaleRunningImportsAsFailed(): int
    {
        return $this->stuckSyncJobMarker->markStuckJobs('shopify');
    }

    public function requestCancelRunningImports(): int
    {
        return SyncJob::query()
            ->where('type', 'import')
            ->where('source', 'shopify')
            ->where('status', SyncJobStatus::Running)
            ->whereNull('cancel_requested_at')
            ->update([
                'cancel_requested_at' => now(),
            ]);
    }

    public function detectAndMarkStuckJobs(): int
    {
        return $this->stuckSyncJobMarker->markStuckJobs();
    }
}
