<?php

namespace App\Console\Commands;

use App\Models\CategoryMapping;
use App\Models\MarketplaceChannel;
use App\Services\Marketplace\Pigu\PiguProductFeedExporter;
use App\Services\Marketplace\Pigu\PiguProductXmlValidator;
use Illuminate\Console\Command;

class PiguValidateProductsXmlCommand extends Command
{
    protected $signature = 'pigu:validate-products-xml
                            {--easy-only : Require category-ids to belong to easy-eligible mappings only}';

    protected $description = 'Validate the generated Pigu/PHH product XML feed';

    public function handle(PiguProductXmlValidator $validator, PiguProductFeedExporter $exporter): int
    {
        $easyCategoryIds = null;

        if ((bool) $this->option('easy-only')) {
            $channel = $exporter->resolveChannel();
            $easyCategoryIds = $this->easyEligibleCategoryIds($channel, $exporter->easyOnlyCollectionHandles($channel));
        }

        $result = $validator->validateFeed(null, $easyCategoryIds);

        $this->line('Products checked: '.$result['products_checked']);
        $this->line('Modifications checked: '.$result['modifications_checked']);
        $this->line('Categories count: '.$result['categories_count']);

        $this->newLine();
        $this->line('Errors by type:');
        if ($result['errors_by_type'] === []) {
            $this->line('  (none)');
        } else {
            foreach ($result['errors_by_type'] as $type => $count) {
                $this->line("  {$type}: {$count}");
            }
        }

        if ($result['errors'] !== []) {
            $this->newLine();
            $this->components->error('First errors:');
            foreach ($result['errors'] as $error) {
                $prefix = isset($error['product_title']) ? "[{$error['product_title']}] " : '';
                $this->line('  - '.$prefix.$error['type'].': '.$error['message']);
            }
        }

        foreach ($result['warnings'] as $warning) {
            $this->newLine();
            $this->components->warn($warning);
        }

        if ($result['valid']) {
            $this->components->info('Pigu product XML validation passed.');

            return self::SUCCESS;
        }

        $this->components->error('Pigu product XML validation failed.');

        return self::FAILURE;
    }

    /**
     * @param  list<string>  $handles
     * @return list<string>
     */
    private function easyEligibleCategoryIds(MarketplaceChannel $channel, array $handles): array
    {
        if ($handles === []) {
            return [];
        }

        return CategoryMapping::query()
            ->where('marketplace_channel_id', $channel->id)
            ->where('enabled', true)
            ->where('export_enabled', true)
            ->where('source_type', 'collection')
            ->whereIn('source_value', $handles)
            ->pluck('target_category_id')
            ->map(fn ($id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
