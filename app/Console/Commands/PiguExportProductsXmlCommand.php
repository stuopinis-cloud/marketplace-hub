<?php

namespace App\Console\Commands;

use App\Services\Marketplace\Pigu\PiguExportOptions;
use App\Services\Marketplace\Pigu\PiguProductFeedExporter;
use Illuminate\Console\Command;
use Throwable;

class PiguExportProductsXmlCommand extends Command
{
    protected $signature = 'pigu:export-products-xml
                            {--easy-only : Export only products in easy-first-wave collections (mapping + dimension + property rules)}
                            {--mapped-only : Export only products linked to enabled Pigu category mappings}
                            {--collection= : Export only products linked to this Shopify collection handle}';

    protected $description = 'Export the Pigu / PHH Marketplace product XML feed';

    public function handle(PiguProductFeedExporter $exporter): int
    {
        $options = PiguExportOptions::fromArray([
            'easy_only' => (bool) $this->option('easy-only'),
            'mapped_only' => (bool) $this->option('mapped-only'),
            'collection' => $this->option('collection'),
        ]);

        try {
            $result = $exporter->export($options);
        } catch (Throwable $exception) {
            $this->components->error('Pigu product XML export failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Pigu product XML export completed.');
        $this->line('Public path: '.$result['absolute_path']);
        $this->line('Public URL: '.$result['public_url']);
        $this->line('Channel ID: '.$result['channel_id']);
        $this->line('Filter easy-only: '.($result['filter']['easy_only'] ? 'yes' : 'no'));
        $this->line('Filter mapped-only: '.($result['filter']['mapped_only'] ? 'yes' : 'no'));
        $this->line('Filter collection: '.($result['filter']['collection'] ?? '—'));
        $this->line('Products considered: '.$result['products_considered']);
        $this->line('Exported products: '.$result['exported_products']);
        $this->line('Exported modifications: '.$result['exported_modifications']);
        $this->line('Skipped products: '.$result['skipped_products']);
        $this->line('Skipped modifications: '.$result['skipped_modifications']);

        foreach ($result['warnings'] ?? [] as $warning) {
            $this->newLine();
            $this->components->warn($warning);
        }

        $this->newLine();
        $this->line('Skipped by code:');
        if ($result['skipped_by_code'] === []) {
            $this->line('  (none)');
        } else {
            foreach ($result['skipped_by_code'] as $code => $count) {
                $this->line("  {$code}: {$count}");
            }
        }

        $this->newLine();
        $this->line('Skipped by source category:');
        if ($result['skipped_by_source_category'] === []) {
            $this->line('  (none)');
        } else {
            foreach ($result['skipped_by_source_category'] as $category => $count) {
                $this->line("  {$category}: {$count}");
            }
        }

        if ($this->output->isVerbose()) {
            $this->printVerboseResolutions($result['export_resolutions'] ?? []);
            $this->printVerboseSkips($result['skipped_rows']);
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array{product_id: int, handle: ?string, title: ?string, chosen_source_handle: ?string, chosen_category_id: ?string, easy_only_allowed: bool}>  $resolutions
     */
    private function printVerboseResolutions(array $resolutions): void
    {
        if ($resolutions === []) {
            return;
        }

        $this->newLine();
        $this->components->info('Verbose category resolutions:');

        foreach ($resolutions as $row) {
            $this->line(sprintf(
                '- Product #%s / %s — chosen_source_handle=%s chosen_category_id=%s easy_only_allowed=%s',
                $row['product_id'] ?? '?',
                $row['handle'] ?? '—',
                $row['chosen_source_handle'] ?? '—',
                $row['chosen_category_id'] ?? '—',
                ($row['easy_only_allowed'] ?? false) ? 'true' : 'false',
            ));
        }
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
            $this->line('  chosen_source_handle: '.($debug['chosen_source_handle'] ?? '—'));
            $this->line('  chosen_category_id: '.($debug['chosen_category_id'] ?? '—'));
            $this->line('  easy_only_allowed: '.(($debug['easy_only_allowed'] ?? false) ? 'true' : 'false'));

            $categories = $row['source_categories'] ?? ($debug['source_categories'] ?? []);
            if (! is_array($categories) || $categories === []) {
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
        }
    }
}
