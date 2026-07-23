<?php

namespace App\Console\Commands;

use App\Models\MarketplaceChannel;
use Illuminate\Console\Command;

class PiguSetupCommand extends Command
{
    protected $signature = 'pigu:setup';

    protected $description = 'Ensure the Pigu / PHH marketplace channel exists';

    public function handle(): int
    {
        $existing = MarketplaceChannel::query()->where('type', 'pigu')->first();
        $config = array_merge([
            'feed_path' => config('marketplace.exports.pigu.feed_path', 'feeds/pigu-products.xml'),
            'require_category_mapping' => true,
            'allow_fallback_product_images' => true,
        ], is_array($existing?->config) ? $existing->config : []);

        $channel = MarketplaceChannel::query()->updateOrCreate(
            ['type' => 'pigu'],
            [
                'name' => 'Pigu / PHH',
                'enabled' => true,
                'config' => $config,
            ],
        );

        $this->components->info('Pigu / PHH marketplace channel is configured.');
        $this->line('ID: '.$channel->id);
        $this->line('Type: '.$channel->type);
        $this->line('Name: '.$channel->name);
        $this->line('Enabled: '.($channel->enabled ? 'yes' : 'no'));
        $this->newLine();
        $this->line('Map Shopify sources to Pigu category ID + name in Category Mappings.');
        $this->line('Then run: php artisan pigu:export-products-xml');

        return self::SUCCESS;
    }
}
