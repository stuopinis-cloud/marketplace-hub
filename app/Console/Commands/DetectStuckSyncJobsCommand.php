<?php

namespace App\Console\Commands;

use App\Services\Sync\ShopifyImportJobGuard;
use Illuminate\Console\Command;

class DetectStuckSyncJobsCommand extends Command
{
    protected $signature = 'sync:detect-stuck';

    protected $description = 'Mark running sync jobs as failed when heartbeat is stale and no worker process exists';

    public function handle(ShopifyImportJobGuard $guard): int
    {
        $marked = $guard->detectAndMarkStuckJobs();

        if ($marked === 0) {
            $this->components->info('No stuck running sync jobs detected.');

            return self::SUCCESS;
        }

        $this->components->warn("Marked {$marked} stuck sync job(s) as failed.");

        return self::SUCCESS;
    }
}
