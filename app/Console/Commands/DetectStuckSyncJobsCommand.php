<?php

namespace App\Console\Commands;

use App\Services\Sync\StuckSyncJobMarker;
use Illuminate\Console\Command;

class DetectStuckSyncJobsCommand extends Command
{
    protected $signature = 'sync:detect-stuck';

    protected $description = 'Mark running sync jobs as failed when heartbeat is stale';

    public function handle(StuckSyncJobMarker $marker): int
    {
        $marked = $marker->markStuckJobs();

        if ($marked === 0) {
            $this->components->info('No stuck running sync jobs detected.');

            return self::SUCCESS;
        }

        $this->components->warn("Marked {$marked} stuck sync job(s) as failed.");

        return self::SUCCESS;
    }
}
