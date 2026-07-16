<?php

namespace App\Jobs;

use App\Enums\SyncJobStatus;
use App\Models\SyncJob;
use App\Services\Marketplace\Varle\VarleReadinessRefreshService;
use App\Services\Sync\MarketplaceJobLock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RefreshVarleReadinessJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    /**
     * @param  array<int, int>|null  $productIds
     */
    public function __construct(
        public readonly int $syncJobId,
        public readonly ?array $productIds = null,
        public readonly int $chunkSize = 100,
    ) {}

    public function handle(VarleReadinessRefreshService $refreshService): void
    {
        $refreshService->run($this->syncJobId, $this->productIds, $this->chunkSize);
    }

    public function failed(?Throwable $exception): void
    {
        MarketplaceJobLock::forceRelease(MarketplaceJobLock::VARLE_READINESS_REFRESH);

        $syncJob = SyncJob::query()->find($this->syncJobId);

        if ($syncJob instanceof SyncJob && in_array($syncJob->status, [SyncJobStatus::Pending, SyncJobStatus::Running], true)) {
            $syncJob->update([
                'status' => SyncJobStatus::Failed,
                'finished_at' => now(),
                'heartbeat_at' => now(),
                'error_message' => $exception?->getMessage() ?? 'Varle readiness refresh failed.',
                'context' => array_merge($syncJob->context ?? [], [
                    'stage' => 'failed',
                ]),
            ]);
        }
    }
}
