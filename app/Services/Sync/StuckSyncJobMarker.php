<?php

namespace App\Services\Sync;

use App\Enums\SyncJobStatus;
use App\Models\SyncJob;
use Illuminate\Support\Facades\Log;

class StuckSyncJobMarker
{
    public function __construct(
        private readonly SyncJobHealthService $healthService,
    ) {}

    public function markStuckJobs(?string $source = null): int
    {
        $query = SyncJob::query()
            ->where('status', SyncJobStatus::Running)
            ->orderBy('id');

        if (filled($source)) {
            $query->where('source', $source);
        }

        $marked = 0;
        $staleMinutes = $this->healthService->stuckAfterMinutes();

        $query->each(function (SyncJob $job) use (&$marked, $staleMinutes): void {
            if (! $this->healthService->isStuck($job)) {
                return;
            }

            $job->update([
                'status' => SyncJobStatus::Failed,
                'finished_at' => now(),
                'error_message' => sprintf(
                    'Marked failed automatically because heartbeat was stale for more than %d minutes.',
                    $staleMinutes,
                ),
                'context' => array_merge($job->context ?? [], [
                    'stuck_detected_at' => now()->toIso8601String(),
                    'stuck_reason' => 'heartbeat_stale',
                    'last_known_stage' => data_get($job->context, 'stage'),
                    'last_known_product_handle' => data_get($job->context, 'current_product_handle'),
                    'last_activity_at' => $this->healthService->lastActivityAt($job)?->toIso8601String(),
                ]),
            ]);

            $marked++;
        });

        if ($marked > 0) {
            Log::warning('Marked stuck sync jobs as failed', [
                'count' => $marked,
                'source' => $source,
                'stale_minutes' => $staleMinutes,
            ]);
        }

        return $marked;
    }

    public function markIfStuck(SyncJob $job): bool
    {
        if ($job->status !== SyncJobStatus::Running || ! $this->healthService->isStuck($job)) {
            return false;
        }

        $staleMinutes = $this->healthService->stuckAfterMinutes();

        $job->update([
            'status' => SyncJobStatus::Failed,
            'finished_at' => now(),
            'error_message' => sprintf(
                'Marked failed automatically because heartbeat was stale for more than %d minutes.',
                $staleMinutes,
            ),
            'context' => array_merge($job->context ?? [], [
                'stuck_detected_at' => now()->toIso8601String(),
                'stuck_reason' => 'heartbeat_stale',
                'last_known_stage' => data_get($job->context, 'stage'),
                'last_known_product_handle' => data_get($job->context, 'current_product_handle'),
                'last_activity_at' => $this->healthService->lastActivityAt($job)?->toIso8601String(),
            ]),
        ]);

        Log::warning('Marked stuck sync job as failed', [
            'sync_job_id' => $job->id,
            'source' => $job->source,
            'stale_minutes' => $staleMinutes,
        ]);

        return true;
    }
}
