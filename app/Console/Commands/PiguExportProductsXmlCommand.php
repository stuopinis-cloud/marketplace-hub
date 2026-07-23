<?php

namespace App\Console\Commands;

use App\Services\Marketplace\Pigu\PiguProductFeedExporter;
use Illuminate\Console\Command;
use Throwable;

class PiguExportProductsXmlCommand extends Command
{
    protected $signature = 'pigu:export-products-xml';

    protected $description = 'Export the Pigu / PHH Marketplace product XML feed';

    public function handle(PiguProductFeedExporter $exporter): int
    {
        try {
            $result = $exporter->export();
        } catch (Throwable $exception) {
            $this->components->error('Pigu product XML export failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Pigu product XML export completed.');
        $this->line('Public path: '.$result['absolute_path']);
        $this->line('Public URL: '.$result['public_url']);
        $this->line('Channel ID: '.$result['channel_id']);
        $this->line('Exported products: '.$result['exported_products']);
        $this->line('Exported modifications: '.$result['exported_modifications']);
        $this->line('Skipped products: '.$result['skipped_products']);
        $this->line('Skipped modifications: '.$result['skipped_modifications']);

        if ($this->output->isVerbose()) {
            $this->printVerboseSkips($result['skipped_rows']);
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $skippedRows
     */
    private function printVerboseSkips(array $skippedRows): void
    {
        $categorySkips = collect($skippedRows)
            ->filter(fn (array $row): bool => in_array($row['code'], [
                'missing_pigu_category_mapping',
                'missing_category_id',
                'missing_category_name',
            ], true))
            ->unique(fn (array $row): string => (string) ($row['product_id'] ?? '').'|'.($row['code'] ?? ''))
            ->values();

        if ($categorySkips->isEmpty()) {
            $this->newLine();
            $this->line('Verbose: no category-mapping skips.');

            return;
        }

        $this->newLine();
        $this->components->info('Verbose category-mapping skips:');

        foreach ($categorySkips as $row) {
            $debug = is_array($row['debug'] ?? null) ? $row['debug'] : [];
            $this->newLine();
            $this->line(sprintf(
                '- Product #%s / %s / %s',
                $row['product_id'] ?? '?',
                $row['handle'] ?? '—',
                $row['title'] ?? '—',
            ));
            $this->line('  Issue: '.($row['code'] ?? '').' — '.($row['message'] ?? ''));
            $this->line('  Searched marketplace_channel_id: '.($debug['marketplace_channel_id'] ?? '—')
                .' ('.($debug['marketplace_channel_name'] ?? '—').')');
            $this->line('  Mapping matched: '.(($debug['mapping_matched'] ?? false) ? 'yes' : 'no'));

            if (! empty($debug['matched_source_type']) || ! empty($debug['matched_source_value'])) {
                $this->line(sprintf(
                    '  Matched mapping source: %s = %s',
                    $debug['matched_source_type'] ?? '—',
                    $debug['matched_source_value'] ?? '—',
                ));
            }

            $categories = is_array($debug['source_categories'] ?? null) ? $debug['source_categories'] : [];
            if ($categories === []) {
                $this->line('  Source categories: none attached');
            } else {
                $this->line('  Source categories:');
                foreach ($categories as $category) {
                    $this->line(sprintf(
                        '    - type=%s handle=%s name=%s external_id=%s',
                        $category['type'] ?? '—',
                        $category['handle'] ?? '—',
                        $category['name'] ?? '—',
                        $category['external_id'] ?? '—',
                    ));
                }
            }

            if (! ($debug['mapping_matched'] ?? false) && ! empty($debug['available_mappings'])) {
                $this->line('  Enabled mappings on channel:');
                foreach ($debug['available_mappings'] as $mapping) {
                    $this->line(sprintf(
                        '    - #%s %s=%s export_enabled=%s target=%s/%s',
                        $mapping['id'] ?? '?',
                        $mapping['source_type'] ?? '—',
                        $mapping['source_value'] ?? '—',
                        ($mapping['export_enabled'] ?? false) ? 'yes' : 'no',
                        $mapping['target_category_id'] ?? '—',
                        $mapping['target_category_name'] ?? '—',
                    ));
                }
            }
        }
    }
}
