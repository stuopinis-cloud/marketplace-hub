<?php

namespace App\Console\Commands;

use App\Services\Automation\DailyMarketplaceSync;
use Illuminate\Console\Command;

class DailySyncCommand extends Command
{
    protected $signature = 'marketplace:daily-sync
                            {--skip-import : Skip Shopify import}
                            {--skip-varle : Skip Varle XML export}
                            {--skip-failed-csv : Skip failed CSV export}';

    protected $description = 'Run the daily Shopify import and Varle XML export workflow';

    public function handle(DailyMarketplaceSync $dailySync): int
    {
        $runShopifyImport = ! $this->option('skip-import');
        $runVarleExport = ! $this->option('skip-varle');
        $generateFailedCsv = ! $this->option('skip-failed-csv');

        $this->components->info('Starting daily marketplace sync...');
        $this->line('Shopify import: '.($runShopifyImport ? 'enabled' : 'skipped'));
        $this->line('Varle export: '.($runVarleExport ? 'enabled' : 'skipped'));
        $this->line('Failed CSV: '.($generateFailedCsv ? 'enabled' : 'skipped'));
        $this->newLine();

        $result = $dailySync->run(
            runShopifyImport: $runShopifyImport,
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
