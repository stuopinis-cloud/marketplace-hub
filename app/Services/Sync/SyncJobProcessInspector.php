<?php

namespace App\Services\Sync;

use App\Models\SyncJob;
use Carbon\CarbonInterface;

class SyncJobProcessInspector
{
    public function __construct(
        private readonly SyncJobHealthService $healthService,
    ) {}

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
        if ($staleMinutes !== null) {
            $threshold = now()->subMinutes($staleMinutes);
            $lastActivity = $this->lastActivityAt($job);

            if ($lastActivity === null) {
                return true;
            }

            return $lastActivity->lte($threshold);
        }

        return $this->healthService->isStuck($job);
    }

    public function shouldMarkStuck(SyncJob $job, ?int $staleMinutes = null): bool
    {
        if ($job->status?->value !== 'running') {
            return false;
        }

        if ($staleMinutes !== null) {
            return $this->isStale($job, $staleMinutes);
        }

        return $this->healthService->isStuck($job);
    }

    public function lastActivityAt(SyncJob $job): ?CarbonInterface
    {
        return $this->healthService->lastActivityAt($job);
    }
}
