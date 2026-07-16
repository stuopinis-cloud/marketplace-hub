<?php

namespace App\Jobs;

use App\Enums\SyncJobStatus;
use App\Models\SyncJob;
use App\Services\Automation\DailyMarketplaceSync;
use App\Services\Sync\MarketplaceJobLock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunDailyMarketplaceSyncJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 7200;

    public int $tries = 1;

    public function __construct(
        public readonly int $syncJobId,
        public readonly bool $runShopifyImport = true,
        public readonly bool $runSupplierSync = true,
        public readonly bool $runReadinessRefresh = true,
        public readonly bool $runVarleExport = true,
        public readonly bool $generateFailedCsv = true,
    ) {}

    public function handle(DailyMarketplaceSync $dailySync): void
    {
        $syncJob = SyncJob::query()->find($this->syncJobId);

        if (! $syncJob instanceof SyncJob) {
            return;
        }

        $lock = MarketplaceJobLock::make(MarketplaceJobLock::MARKETPLACE_DAILY_SYNC);

        if (! $lock->get()) {
            $this->markFailed($syncJob, 'Daily marketplace sync is already running.');

            return;
        }

        $finalized = false;

        try {
            $syncJob->update([
                'status' => SyncJobStatus::Running,
                'started_at' => $syncJob->started_at ?? now(),
                'heartbeat_at' => now(),
                'process_id' => getmypid() ?: null,
                'context' => array_merge($syncJob->context ?? [], [
                    'stage' => 'running',
                    'last_progress_at' => now()->toIso8601String(),
                ]),
            ]);

            $result = $dailySync->run(
                runShopifyImport: $this->runShopifyImport,
                runSupplierSync: $this->runSupplierSync,
                runReadinessRefresh: $this->runReadinessRefresh,
                runVarleExport: $this->runVarleExport,
                generateFailedCsv: $this->generateFailedCsv,
            );

            $status = $result->successful ? SyncJobStatus::Completed : SyncJobStatus::Failed;

            $syncJob->update([
                'status' => $status,
                'finished_at' => now(),
                'heartbeat_at' => now(),
                'error_message' => $result->successful ? null : $result->message,
                'context' => array_merge($syncJob->context ?? [], [
                    'stage' => $result->successful ? 'finished' : 'failed',
                    'summary' => $result->summary,
                    'last_progress_at' => now()->toIso8601String(),
                ]),
            ]);
            $finalized = true;

            if (! $result->successful) {
                throw new \RuntimeException($result->message);
            }
        } catch (Throwable $exception) {
            if (! $finalized) {
                $this->markFailed($syncJob, $exception->getMessage());
                $finalized = true;
            }

            throw $exception;
        } finally {
            if (! $finalized && $syncJob->fresh()?->status === SyncJobStatus::Running) {
                $this->markFailed($syncJob, 'Daily marketplace sync exited while still running.');
            }

            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        MarketplaceJobLock::forceRelease(MarketplaceJobLock::MARKETPLACE_DAILY_SYNC);

        $syncJob = SyncJob::query()->find($this->syncJobId);

        if ($syncJob instanceof SyncJob && $syncJob->status === SyncJobStatus::Running) {
            $this->markFailed($syncJob, $exception?->getMessage() ?? 'Daily marketplace sync failed.');
        }
    }

    private function markFailed(SyncJob $syncJob, string $message): void
    {
        $syncJob->update([
            'status' => SyncJobStatus::Failed,
            'finished_at' => now(),
            'heartbeat_at' => now(),
            'error_message' => $message,
            'context' => array_merge($syncJob->context ?? [], [
                'stage' => 'failed',
                'last_progress_at' => now()->toIso8601String(),
            ]),
        ]);
    }
}
