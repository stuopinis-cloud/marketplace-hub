<?php

namespace App\Console\Commands;

use App\Services\Marketplace\CategoryMappingCsvImportOptions;
use App\Services\Marketplace\CategoryMappingCsvImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class VarleImportCategoryMappingsCommand extends Command
{
    protected $signature = 'varle:import-category-mappings
                            {path : Absolute or relative path to the CSV file}
                            {--dry-run : Validate and preview without writing to the database}
                            {--channel=varle : Marketplace channel type or name}
                            {--priority=100 : Priority to apply to imported mappings}
                            {--source-type=collection : Source type for imported mappings}';

    protected $description = 'Import Shopify collection to Varle category mappings from CSV';

    public function handle(CategoryMappingCsvImporter $importer): int
    {
        $path = (string) $this->argument('path');

        if (! is_file($path)) {
            $this->components->error('CSV file not found: '.$path);

            return self::FAILURE;
        }

        try {
            $channel = CategoryMappingCsvImportOptions::resolveChannel(
                channelIdentifier: (string) $this->option('channel'),
            );

            $options = new CategoryMappingCsvImportOptions(
                marketplaceChannelId: $channel->id,
                sourceType: (string) $this->option('source-type'),
                priority: (int) $this->option('priority'),
                enabled: true,
                exportEnabled: true,
                dryRun: (bool) $this->option('dry-run'),
            );

            $result = $importer->import($path, $options);

            $this->components->info($result->summaryMessage());
            $this->components->twoColumnDetail('Channel', $channel->name);
            $this->components->twoColumnDetail('Source type', $options->sourceType);
            $this->components->twoColumnDetail('Priority', (string) $options->priority);

            if ($result->previewRows !== []) {
                $this->newLine();
                $this->components->info('Preview (first '.count($result->previewRows).' rows):');
                $this->table(
                    ['Line', 'Collection', 'Handle', 'Varle category', 'Action', 'Message'],
                    collect($result->previewRows)->map(fn (array $row): array => [
                        $row['line'],
                        $row['shopify_collection'],
                        $row['shopify_handle'],
                        $row['varle_final_category'],
                        $row['action'],
                        $row['message'],
                    ])->all(),
                );
            }

            if ($result->failedCsvRelativePath !== null) {
                $this->components->warn('Failed/skipped rows exported to: '.$result->failedCsvRelativePath);
                $this->components->twoColumnDetail(
                    'Public URL',
                    Storage::disk('public')->url($result->failedCsvRelativePath),
                );
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
