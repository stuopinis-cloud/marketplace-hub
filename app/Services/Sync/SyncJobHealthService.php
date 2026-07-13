<?php

namespace App\Services\Sync;

use App\Enums\SyncJobStatus;
use App\Models\SyncJob;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class SyncJobHealthService
{
    public const string HEALTH_HEALTHY_RUNNING = 'healthy_running';

    public const string HEALTH_COMPLETED = 'completed';

    public const string HEALTH_FAILED = 'failed';

    public const string HEALTH_STUCK = 'stuck';

    public const string HEALTH_CANCELLED = 'cancelled';

    public const string HEALTH_UNKNOWN = 'unknown';

    public function stuckAfterMinutes(): int
    {
        return max(1, (int) config('marketplace.sync.stuck_after_minutes', 10));
    }

    /**
     * @return array{
     *     health_status: string,
     *     label: string,
     *     color: string,
     *     icon: string,
     *     is_running: bool,
     *     is_stuck: bool,
     *     is_failed: bool,
     *     is_completed: bool,
     *     is_cancelled: bool,
     *     heartbeat_age_seconds: ?int,
     *     progress_percent: ?float,
     *     progress_label: string,
     *     last_activity_at: ?string,
     *     human_message: string,
     *     current_product_handle: ?string,
     *     current_product_index: ?int,
     *     stage: ?string,
     *     variants_for_current_product: ?int
     * }
     */
    public function assess(SyncJob $job): array
    {
        $status = $job->status;
        $lastActivity = $this->lastActivityAt($job);
        $heartbeatAgeSeconds = $this->heartbeatAgeSeconds($job);
        $isStuck = $this->isStuck($job);
        $healthStatus = $this->resolveHealthStatus($job, $isStuck);
        $progress = $this->progressMetrics($job);

        return [
            'health_status' => $healthStatus,
            'label' => $this->labelForHealth($healthStatus),
            'color' => $this->colorForHealth($healthStatus),
            'icon' => $this->iconForHealth($healthStatus),
            'is_running' => $status === SyncJobStatus::Running,
            'is_stuck' => $isStuck,
            'is_failed' => $status === SyncJobStatus::Failed,
            'is_completed' => in_array($status, [SyncJobStatus::Completed, SyncJobStatus::Partial], true),
            'is_cancelled' => $status === SyncJobStatus::Cancelled || $job->cancelled_at !== null,
            'heartbeat_age_seconds' => $heartbeatAgeSeconds,
            'progress_percent' => $progress['percent'],
            'progress_label' => $progress['label'],
            'last_activity_at' => $lastActivity?->toIso8601String(),
            'human_message' => $this->humanMessage($job, $healthStatus, $heartbeatAgeSeconds),
            'current_product_handle' => $this->stringOrNull(data_get($job->context, 'current_product_handle')),
            'current_product_index' => $this->intOrNull(data_get($job->context, 'current_product_index')),
            'stage' => $this->stringOrNull(data_get($job->context, 'stage')),
            'variants_for_current_product' => $this->intOrNull(data_get($job->context, 'variants_for_current_product')),
        ];
    }

    public function isStuck(SyncJob $job): bool
    {
        if ($job->status !== SyncJobStatus::Running) {
            return false;
        }

        $threshold = now()->subMinutes($this->stuckAfterMinutes());

        if ($job->heartbeat_at !== null) {
            return $job->heartbeat_at->lte($threshold);
        }

        if ($job->started_at !== null) {
            return $job->started_at->lte($threshold);
        }

        return true;
    }

    public function lastActivityAt(SyncJob $job): ?CarbonInterface
    {
        return $job->heartbeat_at ?? $job->started_at;
    }

    public function heartbeatAgeSeconds(SyncJob $job): ?int
    {
        $heartbeat = $job->heartbeat_at;

        if ($heartbeat === null) {
            return null;
        }

        return (int) $heartbeat->diffInSeconds(now(), absolute: true);
    }

    public function heartbeatAgeLabel(SyncJob $job): string
    {
        $seconds = $this->heartbeatAgeSeconds($job);

        if ($seconds === null) {
            return 'never';
        }

        if ($seconds < 60) {
            return $seconds.' sec ago';
        }

        $minutes = (int) floor($seconds / 60);

        return $minutes.' min ago';
    }

    /**
     * @return array{percent: ?float, label: string, processed: int, total: int}
     */
    public function progressMetrics(SyncJob $job): array
    {
        $total = (int) $job->total_items;
        $processed = (int) $job->success_items + (int) $job->failed_items;
        $contextIndex = $this->intOrNull(data_get($job->context, 'current_product_index'));

        $label = '—';

        if ($contextIndex !== null && $total > 0) {
            $label = $contextIndex.' / '.$total;
        } elseif ($total > 0) {
            $label = $processed.' / '.$total;
        } elseif ($contextIndex !== null) {
            $label = (string) $contextIndex;
        }

        $percent = null;

        if ($total > 0) {
            $percent = min(100, round(($processed / $total) * 100, 1));
        }

        return [
            'percent' => $percent,
            'label' => $label,
            'processed' => $processed,
            'total' => $total,
        ];
    }

    public function durationLabel(SyncJob $job): ?string
    {
        if ($job->started_at === null) {
            return null;
        }

        $end = $job->finished_at ?? now();

        $seconds = (int) $job->started_at->diffInSeconds($end, absolute: true);

        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = (int) floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        return $minutes.'m '.$remainingSeconds.'s';
    }

    private function resolveHealthStatus(SyncJob $job, bool $isStuck): string
    {
        if ($job->status === SyncJobStatus::Cancelled || $job->cancelled_at !== null) {
            return self::HEALTH_CANCELLED;
        }

        if ($job->status === SyncJobStatus::Failed) {
            return self::HEALTH_FAILED;
        }

        if (in_array($job->status, [SyncJobStatus::Completed, SyncJobStatus::Partial], true)
            && $job->finished_at !== null) {
            return self::HEALTH_COMPLETED;
        }

        if ($job->status === SyncJobStatus::Running) {
            return $isStuck ? self::HEALTH_STUCK : self::HEALTH_HEALTHY_RUNNING;
        }

        return self::HEALTH_UNKNOWN;
    }

    private function labelForHealth(string $healthStatus): string
    {
        return match ($healthStatus) {
            self::HEALTH_HEALTHY_RUNNING => 'Running',
            self::HEALTH_COMPLETED => 'Completed',
            self::HEALTH_FAILED => 'Failed',
            self::HEALTH_STUCK => 'Stuck',
            self::HEALTH_CANCELLED => 'Cancelled',
            default => 'Unknown',
        };
    }

    private function colorForHealth(string $healthStatus): string
    {
        return match ($healthStatus) {
            self::HEALTH_HEALTHY_RUNNING => 'info',
            self::HEALTH_COMPLETED => 'success',
            self::HEALTH_FAILED => 'danger',
            self::HEALTH_STUCK => 'warning',
            self::HEALTH_CANCELLED => 'gray',
            default => 'gray',
        };
    }

    private function iconForHealth(string $healthStatus): string
    {
        return match ($healthStatus) {
            self::HEALTH_HEALTHY_RUNNING => 'heroicon-o-arrow-path',
            self::HEALTH_COMPLETED => 'heroicon-o-check-circle',
            self::HEALTH_FAILED => 'heroicon-o-x-circle',
            self::HEALTH_STUCK => 'heroicon-o-exclamation-triangle',
            self::HEALTH_CANCELLED => 'heroicon-o-no-symbol',
            default => 'heroicon-o-question-mark-circle',
        };
    }

    private function humanMessage(SyncJob $job, string $healthStatus, ?int $heartbeatAgeSeconds): string
    {
        return match ($healthStatus) {
            self::HEALTH_COMPLETED => $job->status === SyncJobStatus::Partial
                ? 'Import completed with some failed items.'
                : 'Last import completed successfully.',
            self::HEALTH_FAILED => 'Import failed: '.($job->error_message ?: 'Unknown error.'),
            self::HEALTH_STUCK => sprintf(
                'Import appears stuck. Last heartbeat was %s.',
                $heartbeatAgeSeconds === null
                    ? 'never recorded'
                    : $this->formatAgeMinutes($heartbeatAgeSeconds),
            ),
            self::HEALTH_HEALTHY_RUNNING => 'Import is running normally.',
            self::HEALTH_CANCELLED => 'Import was cancelled.',
            default => 'Import status is unknown.',
        };
    }

    private function formatAgeMinutes(int $seconds): string
    {
        $minutes = max(1, (int) ceil($seconds / 60));

        return $minutes.' minute'.($minutes === 1 ? '' : 's').' ago';
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
