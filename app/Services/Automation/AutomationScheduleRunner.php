<?php

namespace App\Services\Automation;

use App\Enums\SyncJobStatus;
use App\Models\AutomationSchedule;
use App\Models\SyncJob;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

class AutomationScheduleRunner
{
    public function __construct(
        private readonly DailyMarketplaceSync $dailyMarketplaceSync,
    ) {}

    public function runDueSchedules(): void
    {
        AutomationSchedule::query()
            ->where('enabled', true)
            ->where('frequency', 'daily')
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->orderBy('id')
            ->each(fn (AutomationSchedule $schedule) => $this->runSchedule($schedule));
    }

    public function runSchedule(AutomationSchedule $schedule): AutomationScheduleRunResult
    {
        if (! $schedule->enabled) {
            return $this->markSkipped($schedule, 'Schedule is disabled.');
        }

        if ($schedule->frequency !== 'daily') {
            return $this->markSkipped($schedule, 'Unsupported frequency: '.$schedule->frequency);
        }

        if ($schedule->next_run_at !== null && $schedule->next_run_at->isFuture()) {
            return $this->markSkipped($schedule, 'Schedule is not due yet.');
        }

        if ($blockReason = $this->runningMarketplaceSyncReason()) {
            return $this->markBlocked($schedule, $blockReason);
        }

        return match ($schedule->type) {
            'daily_marketplace_sync' => $this->runDailyMarketplaceSync($schedule),
            default => $this->markSkipped($schedule, 'Unsupported schedule type: '.$schedule->type),
        };
    }

    public function calculateNextRunAt(AutomationSchedule $schedule, ?CarbonInterface $from = null): Carbon
    {
        $timezone = $schedule->timezone ?: 'Europe/Vilnius';
        $fromInTimezone = ($from ?? Date::now())->copy()->timezone($timezone);
        ['hour' => $hour, 'minute' => $minute] = $this->parseRunTime($schedule->run_time);

        $nextRun = $fromInTimezone->copy()
            ->startOfDay()
            ->setTime($hour, $minute, 0);

        if ($nextRun->lessThanOrEqualTo($fromInTimezone)) {
            $nextRun->addDay();
        }

        return $nextRun->utc();
    }

    public function refreshNextRunAt(AutomationSchedule $schedule, ?CarbonInterface $from = null): AutomationSchedule
    {
        if (blank($schedule->run_time)) {
            return $schedule;
        }

        $schedule->next_run_at = $this->calculateNextRunAt($schedule, $from);
        $schedule->save();

        return $schedule->refresh();
    }

    private function runDailyMarketplaceSync(AutomationSchedule $schedule): AutomationScheduleRunResult
    {
        $result = $this->dailyMarketplaceSync->run(
            runShopifyImport: $schedule->run_shopify_import,
            runVarleExport: $schedule->run_varle_export,
            generateFailedCsv: $schedule->generate_failed_csv,
        );

        $schedule->last_run_at = now();

        if ($result->successful) {
            $schedule->last_status = 'success';
            $schedule->last_error = null;
            $schedule->next_run_at = $this->calculateNextRunAt($schedule, now());
            $schedule->save();

            return AutomationScheduleRunResult::success($result->message);
        }

        $schedule->last_status = 'failed';
        $schedule->last_error = $result->message;
        $schedule->next_run_at = $this->calculateNextRunAt($schedule, now());
        $schedule->save();

        return AutomationScheduleRunResult::failed($result->message);
    }

    private function markBlocked(AutomationSchedule $schedule, string $reason): AutomationScheduleRunResult
    {
        $schedule->last_status = 'blocked';
        $schedule->last_error = $reason;
        $schedule->save();

        return AutomationScheduleRunResult::blocked($reason);
    }

    private function markSkipped(AutomationSchedule $schedule, string $reason): AutomationScheduleRunResult
    {
        $schedule->last_status = 'skipped';
        $schedule->last_error = $reason;
        $schedule->save();

        return AutomationScheduleRunResult::skipped($reason);
    }

    private function runningMarketplaceSyncReason(): ?string
    {
        $runningJob = SyncJob::query()
            ->where('status', SyncJobStatus::Running)
            ->where(function ($query): void {
                $query
                    ->where(function ($query): void {
                        $query->where('type', 'import')->where('source', 'shopify');
                    })
                    ->orWhere(function ($query): void {
                        $query->where('type', 'export')->where('channel', 'varle');
                    });
            })
            ->latest('id')
            ->first();

        if ($runningJob === null) {
            return null;
        }

        $label = $runningJob->type === 'import'
            ? 'Shopify import'
            : 'Varle export';

        return "{$label} sync job #{$runningJob->id} is still running.";
    }

    /**
     * @return array{hour: int, minute: int}
     */
    private function parseRunTime(mixed $runTime): array
    {
        $value = is_string($runTime) ? $runTime : '03:30';
        $parts = explode(':', $value);

        return [
            'hour' => (int) ($parts[0] ?? 3),
            'minute' => (int) ($parts[1] ?? 30),
        ];
    }
}
