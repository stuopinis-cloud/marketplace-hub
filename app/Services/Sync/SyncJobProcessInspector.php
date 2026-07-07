<?php

namespace App\Services\Sync;

use App\Models\SyncJob;
use Carbon\CarbonInterface;

class SyncJobProcessInspector
{
    public const int STALE_MINUTES = 15;

    public function isProcessRunning(?int $processId): bool
    {
        if ($processId === null || $processId <= 0) {
            return false;
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($processId, 0);
        }

        return false;
    }

    public function isStale(SyncJob $job, ?int $staleMinutes = null): bool
    {
        $staleMinutes ??= self::STALE_MINUTES;
        $lastActivity = $job->heartbeat_at ?? $job->updated_at ?? $job->started_at;

        if ($lastActivity === null) {
            return true;
        }

        return $lastActivity->lte(now()->subMinutes($staleMinutes));
    }

    public function shouldMarkStuck(SyncJob $job, ?int $staleMinutes = null): bool
    {
        if ($job->status?->value !== 'running') {
            return false;
        }

        if (! $this->isStale($job, $staleMinutes)) {
            return false;
        }

        return ! $this->isProcessRunning($job->process_id);
    }

    public function lastActivityAt(SyncJob $job): ?CarbonInterface
    {
        return $job->heartbeat_at ?? $job->updated_at ?? $job->started_at;
    }
}
