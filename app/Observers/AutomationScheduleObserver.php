<?php

namespace App\Observers;

use App\Models\AutomationSchedule;
use App\Services\Automation\AutomationScheduleRunner;

class AutomationScheduleObserver
{
    public function saving(AutomationSchedule $schedule): void
    {
        if (blank($schedule->run_time)) {
            return;
        }

        if ($schedule->isDirty('next_run_at')) {
            return;
        }

        if ($schedule->next_run_at === null || $schedule->isDirty([
            'enabled',
            'frequency',
            'run_time',
            'timezone',
        ])) {
            $schedule->next_run_at = app(AutomationScheduleRunner::class)
                ->calculateNextRunAt($schedule);
        }
    }
}
