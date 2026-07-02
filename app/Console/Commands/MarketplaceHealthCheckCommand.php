<?php

namespace App\Console\Commands;

use App\Services\Deployment\MarketplaceHealthChecker;
use Illuminate\Console\Command;

class MarketplaceHealthCheckCommand extends Command
{
    protected $signature = 'marketplace:health-check';

    protected $description = 'Run production readiness checks for Marketplace Hub';

    public function handle(MarketplaceHealthChecker $healthChecker): int
    {
        $report = $healthChecker->detailedReport();

        $this->components->info('Marketplace Hub health check');
        $this->line('Status: '.$report['status']);
        $this->line('Time: '.$report['time']);
        $this->newLine();

        foreach ($report['checks'] as $name => $check) {
            if ($name === 'sync_jobs') {
                $this->printSyncJobsSummary($check);

                continue;
            }

            $status = (string) ($check['status'] ?? 'unknown');
            $message = (string) ($check['message'] ?? '');
            $line = ucfirst(str_replace('_', ' ', $name)).': '.$status;

            if ($message !== '') {
                $line .= ' — '.$message;
            }

            if ($status === 'ok') {
                $this->components->info($line);
            } else {
                $this->components->error($line);
            }
        }

        return ($report['status'] ?? 'error') === 'ok' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $check
     */
    private function printSyncJobsSummary(array $check): void
    {
        $this->components->info('Latest sync jobs');

        $import = $check['latest_shopify_import'] ?? null;
        if (is_array($import)) {
            $this->line(sprintf(
                '- Shopify import #%s: %s (success %d / failed %d)',
                $import['id'] ?? '—',
                $import['status'] ?? '—',
                $import['success_items'] ?? 0,
                $import['failed_items'] ?? 0,
            ));
        } else {
            $this->line('- Shopify import: none');
        }

        $export = $check['latest_varle_export'] ?? null;
        if (is_array($export)) {
            $this->line(sprintf(
                '- Varle export #%s: %s (success %d / failed %d)',
                $export['id'] ?? '—',
                $export['status'] ?? '—',
                $export['success_items'] ?? 0,
                $export['failed_items'] ?? 0,
            ));
        } else {
            $this->line('- Varle export: none');
        }

        $this->newLine();
    }
}
