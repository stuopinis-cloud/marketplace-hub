<?php

namespace App\Console\Commands;

use App\Services\Marketplace\Pigu\PiguEasyCategoriesCsvImporter;
use Illuminate\Console\Command;
use Throwable;

class PiguImportEasyCategoriesCsvCommand extends Command
{
    protected $signature = 'pigu:import-easy-categories-csv
                            {path : Absolute or relative path to the easy categories CSV}
                            {--dry-run : Preview changes without writing to the database}';

    protected $description = 'Import Pigu category mappings, dimension rules, and property rules from easy categories CSV';

    public function handle(PiguEasyCategoriesCsvImporter $importer): int
    {
        $path = (string) $this->argument('path');

        if (! str_starts_with($path, '/')) {
            $path = base_path($path);
        }

        $dryRun = (bool) $this->option('dry-run');

        try {
            $result = $importer->import($path, $dryRun);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info($dryRun
            ? 'Pigu easy categories CSV dry-run completed.'
            : 'Pigu easy categories CSV import completed.');

        $this->line('Rows read: '.$result['rows_read']);
        $this->line('Rows importable: '.$result['rows_importable']);
        $this->line('Rows skipped: '.$result['rows_skipped']);
        $this->line('Category mappings create/update: '.$result['category_mappings']['create'].'/'.$result['category_mappings']['update']);
        $this->line('Dimension rules create/update: '.$result['dimension_rules']['create'].'/'.$result['dimension_rules']['update']);
        $this->line('Property rules create/update: '.$result['property_rules']['create'].'/'.$result['property_rules']['update']);

        if ($result['duplicate_handles'] !== []) {
            $this->newLine();
            $this->components->warn('Duplicate Shopify handles (last row wins):');
            foreach ($result['duplicate_handles'] as $duplicate) {
                $this->line(sprintf(
                    '- %s: line %d loses to line %d',
                    $duplicate['handle'],
                    $duplicate['losing_line'],
                    $duplicate['winning_line'],
                ));
            }
        }

        if ($result['skipped'] !== [] && $this->output->isVerbose()) {
            $this->newLine();
            $this->line('Skipped rows:');
            foreach ($result['skipped'] as $skipped) {
                $this->line(sprintf(
                    '- line %d (%s): %s',
                    $skipped['line'],
                    $skipped['handle'] !== '' ? $skipped['handle'] : '—',
                    $skipped['reason'],
                ));
            }
        }

        return self::SUCCESS;
    }
}
