<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PiguExportFailedCsvCommand extends Command
{
    protected $signature = 'pigu:export-failed-csv';

    protected $description = 'Show path to the latest Pigu skipped/failed export CSV';

    public function handle(): int
    {
        $relative = (string) config('marketplace.exports.pigu.failed_csv_path', 'exports/pigu_failed_latest.csv');

        if (! Storage::disk('public')->exists($relative)) {
            $this->components->warn('No Pigu failed CSV found. Run pigu:export-products-xml first.');

            return self::FAILURE;
        }

        $absolute = storage_path('app/public/'.$relative);
        $this->components->info('Pigu failed/skipped CSV ready.');
        $this->line('Public path: '.$absolute);
        $this->line('Relative path: '.$relative);

        return self::SUCCESS;
    }
}
