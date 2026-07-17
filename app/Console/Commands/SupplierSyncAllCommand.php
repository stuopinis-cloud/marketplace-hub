<?php

namespace App\Console\Commands;

use App\Services\Suppliers\SupplierSyncManager;
use App\Services\Suppliers\SupplierSyncOptions;
use Illuminate\Console\Command;

class SupplierSyncAllCommand extends Command
{
    protected $signature = 'supplier:sync-all
                            {--dry-run : Parse and match without writing supplier stock}
                            {--only= : Comma-separated supplier codes to sync}
                            {--force : Ignore sync_interval_minutes and force every selected supplier}';

    protected $description = 'Sync all enabled suppliers';

    public function handle(SupplierSyncManager $manager): int
    {
        $only = $this->parseOnlyOption();

        $options = new SupplierSyncOptions(
            dryRun: (bool) $this->option('dry-run'),
            verbose: $this->output->isVerbose(),
            force: (bool) $this->option('force'),
            only: $only,
        );

        $results = $manager->syncAll($options);
        $hadError = false;

        if ($results === []) {
            $this->components->warn('No enabled suppliers matched the selection.');

            return self::SUCCESS;
        }

        foreach ($results as $result) {
            $this->components->info(($result['name'] ?? $result['code']).' ('.$result['code'].')');

            if (isset($result['skipped']) && $result['skipped'] === 'not_due') {
                $this->line('Skipped: not due yet (use --force to override).');

                continue;
            }

            if (isset($result['error'])) {
                $hadError = true;
                $this->components->error($result['error']);

                continue;
            }

            if (! isset($result['result'])) {
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

    /**
     * @return list<string>|null
     */
    private function parseOnlyOption(): ?array
    {
        $raw = $this->option('only');

        if (! is_string($raw) || blank($raw)) {
            return null;
        }

        return array_values(array_filter(array_map(
            fn (string $code): string => mb_strtolower(trim($code)),
            explode(',', $raw),
        )));
    }
}
