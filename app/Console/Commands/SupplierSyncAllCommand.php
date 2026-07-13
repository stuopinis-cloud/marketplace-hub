<?php

namespace App\Console\Commands;

use App\Services\Suppliers\SupplierSyncManager;
use App\Services\Suppliers\SupplierSyncOptions;
use Illuminate\Console\Command;

class SupplierSyncAllCommand extends Command
{
    protected $signature = 'supplier:sync-all
                            {--dry-run : Parse and match without writing supplier stock}';

    protected $description = 'Sync all enabled suppliers';

    public function handle(SupplierSyncManager $manager): int
    {
        $options = new SupplierSyncOptions(
            dryRun: (bool) $this->option('dry-run'),
            verbose: $this->output->isVerbose(),
        );

        $results = $manager->syncAll($options);
        $hadError = false;

        foreach ($results as $result) {
            $this->components->info(($result['name'] ?? $result['code']).' ('.$result['code'].')');

            if (isset($result['error'])) {
                $hadError = true;
                $this->components->error($result['error']);

                continue;
            }

            $sync = $result['result'];
            $this->line('Sync job ID: '.$sync->syncJobId);
            $this->line('Matched: '.$sync->matched.' | Failed: '.$sync->failedRows);
        }

        if ($hadError) {
            return self::FAILURE;
        }

        $this->components->info('All enabled supplier syncs finished.');

        return self::SUCCESS;
    }
}
