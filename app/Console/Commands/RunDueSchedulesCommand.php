<?php

namespace App\Console\Commands;

use App\Services\Automation\AutomationScheduleRunner;
use Illuminate\Console\Command;

class RunDueSchedulesCommand extends Command
{
    protected $signature = 'marketplace:run-due-schedules';

    protected $description = 'Run enabled automation schedules that are due';

    public function handle(AutomationScheduleRunner $runner): int
    {
        $runner->runDueSchedules();

        $this->components->info('Due automation schedules processed.');

        return self::SUCCESS;
    }
}
