<?php

namespace App\Jobs;

use App\Enums\SyncJobStatus;
use App\Models\SyncJob;
use App\Services\Automation\DailyMarketplaceSync;
use App\Services\Automation\DailyMarketplaceSyncResult;
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

            $this->finalizeFromResult($syncJob, $result);
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

    private function finalizeFromResult(SyncJob $syncJob, DailyMarketplaceSyncResult $result): void
    {
        $status = match ($result->outcome) {
            DailyMarketplaceSyncResult::OUTCOME_PARTIAL => SyncJobStatus::Partial,
            DailyMarketplaceSyncResult::OUTCOME_CANCELLED => SyncJobStatus::Cancelled,
            DailyMarketplaceSyncResult::OUTCOME_FAILED => SyncJobStatus::Failed,
            default => SyncJobStatus::Completed,
        };

        $varleExport = $result->summary['varle_export'] ?? [];
        $failedCsv = $result->summary['failed_csv'] ?? null;
        $warnings = $result->warnings;

        $context = array_merge($syncJob->context ?? [], [
            'stage' => $result->successful
                ? ($result->isPartial() ? 'finished_with_warnings' : 'finished')
                : ($result->outcome === DailyMarketplaceSyncResult::OUTCOME_CANCELLED ? 'cancelled' : 'failed'),
            'outcome' => $result->outcome,
            'summary' => $result->summary,
            'warnings' => $warnings,
            'last_progress_at' => now()->toIso8601String(),
        ]);

        if ($warnings !== []) {
            $context['warning'] = $warnings[0];
        }

        if (isset($varleExport['skipped_variants'])) {
            $context['varle_skipped_variants'] = (int) $varleExport['skipped_variants'];
        }

        if (isset($varleExport['exported_variants'])) {
            $context['varle_exported_variants'] = (int) $varleExport['exported_variants'];
        }

        if (is_array($failedCsv)) {
            $context['failed_csv'] = $failedCsv;
        }

        $syncJob->update([
            'status' => $status,
            'finished_at' => now(),
            'heartbeat_at' => now(),
            'error_message' => $result->successful ? null : $result->message,
            'context' => $context,
        ]);
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
                'outcome' => DailyMarketplaceSyncResult::OUTCOME_FAILED,
                'last_progress_at' => now()->toIso8601String(),
            ]),
        ]);
    }
}
