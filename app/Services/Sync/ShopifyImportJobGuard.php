<?php

namespace App\Services\Sync;

use App\Enums\SyncJobStatus;
use App\Models\SyncJob;

class ShopifyImportJobGuard
{
    public function __construct(
        private readonly SyncJobProcessInspector $processInspector,
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
                if ($this->processInspector->isStale($job)) {
                    return false;
                }

                return $this->processInspector->isProcessRunning($job->process_id);
            });
    }

    public function markStaleRunningImportsAsFailed(): int
    {
        $marked = 0;

        SyncJob::query()
            ->where('type', 'import')
            ->where('source', 'shopify')
            ->where('status', SyncJobStatus::Running)
            ->orderBy('id')
            ->each(function (SyncJob $job) use (&$marked): void {
                if (! $this->processInspector->shouldMarkStuck($job)) {
                    return;
                }

                $job->update([
                    'status' => SyncJobStatus::Failed,
                    'finished_at' => now(),
                    'error_message' => 'Import marked failed because the worker stopped responding.',
                    'context' => array_merge($job->context ?? [], [
                        'stale_detected_at' => now()->toIso8601String(),
                        'last_activity_at' => $this->processInspector->lastActivityAt($job)?->toIso8601String(),
                        'process_id' => $job->process_id,
                    ]),
                ]);

                $marked++;
            });

        return $marked;
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
        $marked = 0;

        SyncJob::query()
            ->where('status', SyncJobStatus::Running)
            ->orderBy('id')
            ->each(function (SyncJob $job) use (&$marked): void {
                if (! $this->processInspector->shouldMarkStuck($job)) {
                    return;
                }

                $job->update([
                    'status' => SyncJobStatus::Failed,
                    'finished_at' => now(),
                    'error_message' => 'Marked stuck by sync:detect-stuck.',
                    'context' => array_merge($job->context ?? [], [
                        'stale_detected_at' => now()->toIso8601String(),
                        'last_activity_at' => $this->processInspector->lastActivityAt($job)?->toIso8601String(),
                        'process_id' => $job->process_id,
                    ]),
                ]);

                $marked++;
            });

        return $marked;
    }
}
