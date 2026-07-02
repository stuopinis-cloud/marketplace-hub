<?php

namespace App\Console\Commands;

use App\Models\CategoryMapping;
use App\Models\MarketplaceChannel;
use Illuminate\Console\Command;

class VarleSeedCategoryMappingsCommand extends Command
{
    protected $signature = 'varle:seed-category-mappings';

    protected $description = 'Seed example Varle.lt category mappings';

    public function handle(): int
    {
        $channel = MarketplaceChannel::query()->firstOrCreate(
            [
                'type' => 'varle',
                'name' => 'Varle.lt',
            ],
            [
                'enabled' => true,
                'config' => [
                    'delivery_text' => '1-2 d.d.',
                    'default_category' => 'Kita',
                    'export_zero_stock' => true,
                    'price_multiplier' => 1,
                    'feed_filename' => 'varle.xml',
                ],
            ],
        );

        $examples = [
            [
                'source_type' => 'product_type',
                'source_value' => 'Šarvinės liemenės',
                'target_category_path' => 'Taktinė ekipuotė -> Šarvinės liemenės',
                'priority' => 100,
            ],
            [
                'source_type' => 'collection',
                'source_value' => 'sarvines-liemenes',
                'target_category_path' => 'Taktinė ekipuotė -> Šarvinės liemenės',
                'priority' => 100,
            ],
        ];

        foreach ($examples as $example) {
            CategoryMapping::query()->updateOrCreate(
                [
                    'marketplace_channel_id' => $channel->id,
                    'source_type' => $example['source_type'],
                    'source_value' => $example['source_value'],
                ],
                [
                    'target_category_path' => $example['target_category_path'],
                    'priority' => $example['priority'],
                    'enabled' => true,
                ],
            );
        }

        $this->components->info('Example Varle category mappings seeded for channel: '.$channel->name);

        return self::SUCCESS;
    }
}
