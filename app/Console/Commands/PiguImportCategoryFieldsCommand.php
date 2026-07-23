<?php

namespace App\Console\Commands;

use App\Services\Marketplace\Pigu\PiguCategoryFieldImporter;
use Illuminate\Console\Command;
use Throwable;

class PiguImportCategoryFieldsCommand extends Command
{
    protected $signature = 'pigu:import-category-fields
                            {path : Absolute or relative path to Categories fields and values.xlsx/csv}';

    protected $description = 'Import Pigu category fields and allowed values from PHH Excel/CSV';

    public function handle(PiguCategoryFieldImporter $importer): int
    {
        $path = (string) $this->argument('path');

        if (! str_starts_with($path, '/')) {
            $path = base_path($path);
        }

        try {
            $result = $importer->import($path);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Pigu category fields import completed.');
        $this->line('Imported: '.$result['imported']);
        $this->line('Updated: '.$result['updated']);
        $this->line('Skipped: '.$result['skipped']);

        return self::SUCCESS;
    }
}
