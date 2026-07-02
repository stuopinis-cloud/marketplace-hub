<?php

namespace App\Console\Commands;

use App\Services\Sync\SyncJobFailedCsvExporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class VarleExportFailedCsvCommand extends Command
{
    protected $signature = 'varle:export-failed-csv {syncJobId? : Sync job ID to export}';

    protected $description = 'Export failed/skipped Varle export sync job items to CSV';

    public function handle(SyncJobFailedCsvExporter $exporter): int
    {
        $syncJobId = $this->argument('syncJobId');
        $syncJob = $exporter->resolveSyncJob(
            filled($syncJobId) ? (int) $syncJobId : null,
        );

        if ($syncJob === null) {
            $this->components->error('No Varle export sync job found.');

            return self::FAILURE;
        }

        $relativePath = $exporter->export($syncJob);
        $fullPath = Storage::disk('public')->path($relativePath);

        $this->components->info('Failed/skipped items exported to CSV.');
        $this->line('Sync job ID: '.$syncJob->id);
        $this->line('Path: '.$fullPath);
        $this->line('URL: '.$exporter->publicUrl($relativePath));

        return self::SUCCESS;
    }
}
