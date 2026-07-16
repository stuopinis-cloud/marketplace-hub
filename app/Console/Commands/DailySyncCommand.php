<?php

namespace App\Console\Commands;

use App\Services\Automation\DailyMarketplaceSync;
use App\Services\Sync\MarketplaceJobDispatcher;
use Illuminate\Console\Command;

class DailySyncCommand extends Command
{
    protected $signature = 'marketplace:daily-sync
                            {--sync : Run synchronously in this process (not for production cron)}
                            {--skip-import : Skip Shopify import}
                            {--skip-supplier : Skip enabled supplier syncs}
                            {--skip-readiness : Skip Varle readiness refresh}
                            {--skip-varle : Skip Varle XML export}
                            {--skip-failed-csv : Skip failed CSV export}';

    protected $description = 'Queue (or optionally run) the daily Shopify import, supplier sync, readiness refresh, and Varle XML export workflow';

    public function handle(MarketplaceJobDispatcher $dispatcher, DailyMarketplaceSync $dailySync): int
    {
        $runShopifyImport = ! $this->option('skip-import');
        $runSupplierSync = ! $this->option('skip-supplier');
        $runReadinessRefresh = ! $this->option('skip-readiness');
        $runVarleExport = ! $this->option('skip-varle');
        $generateFailedCsv = ! $this->option('skip-failed-csv');

        if (! $this->option('sync')) {
            $result = $dispatcher->dispatchDailySync(
                runShopifyImport: $runShopifyImport,
                runSupplierSync: $runSupplierSync,
                runReadinessRefresh: $runReadinessRefresh,
                runVarleExport: $runVarleExport,
                generateFailedCsv: $generateFailedCsv,
            );

            if ($result->alreadyRunning) {
                $this->components->warn($result->message ?? 'Daily marketplace sync is already running.');

                return self::SUCCESS;
            }

            $this->components->info($result->message ?? 'Daily marketplace sync queued.');

            if ($result->syncJob !== null) {
                $this->line('Sync job ID: '.$result->syncJob->id);
            }

            return self::SUCCESS;
        }

        $this->components->info('Starting daily marketplace sync synchronously...');
        $this->line('Shopify import: '.($runShopifyImport ? 'enabled' : 'skipped'));
        $this->line('Supplier sync: '.($runSupplierSync ? 'enabled' : 'skipped'));
        $this->line('Readiness refresh: '.($runReadinessRefresh ? 'enabled' : 'skipped'));
        $this->line('Varle export: '.($runVarleExport ? 'enabled' : 'skipped'));
        $this->line('Failed CSV: '.($generateFailedCsv ? 'enabled' : 'skipped'));
        $this->newLine();

        $result = $dailySync->run(
            runShopifyImport: $runShopifyImport,
            runSupplierSync: $runSupplierSync,
            runReadinessRefresh: $runReadinessRefresh,
            runVarleExport: $runVarleExport,
            generateFailedCsv: $generateFailedCsv,
        );

        if (isset($result->summary['shopify_import'])) {
            $import = $result->summary['shopify_import'];
            $this->components->info('Shopify import');
            $this->line('Sync job ID: '.$import['sync_job_id']);
            $this->line('Imported products: '.$import['products_imported']);
            $this->line('Imported variants: '.$import['variants_imported']);
            $this->line('Failed items: '.$import['failed_items']);
            $this->newLine();
        }

        if (isset($result->summary['supplier_sync'])) {
            $supplierSync = $result->summary['supplier_sync'];
            $this->components->info('Supplier sync');
            $this->line('M-Tac: '.(isset($supplierSync['mtac']['error']) ? $supplierSync['mtac']['error'] : 'ok'));
            $this->line('Helikon: '.(isset($supplierSync['helik']['error']) ? $supplierSync['helik']['error'] : 'ok'));
            $this->line('CSV suppliers synced: '.count($supplierSync['csv'] ?? []));
            $this->newLine();
        }

        if (isset($result->summary['varle_export'])) {
            $export = $result->summary['varle_export'];
            $this->components->info('Varle export');
            $this->line('Sync job ID: '.$export['sync_job_id']);
            $this->line('Exported variants: '.$export['exported_variants']);
            $this->line('Skipped variants: '.$export['skipped_variants']);
            $this->line('Feed path: '.$export['feed_path']);
            $this->line('Public URL: '.$export['public_url']);
            $this->newLine();
        }

        if (isset($result->summary['failed_csv'])) {
            $csv = $result->summary['failed_csv'];
            $this->components->info('Failed CSV export');
            $this->line('Sync job ID: '.$csv['sync_job_id']);
            $this->line('Path: '.$csv['path']);
            $this->line('URL: '.$csv['url']);
            $this->newLine();
        }

        if (! $result->successful) {
            $this->components->error($result->message);

            return self::FAILURE;
        }

        $this->components->info($result->message);

        return self::SUCCESS;
    }
}
