<?php

namespace App\Console\Commands;

use App\Enums\FeedFileStatus;
use App\Models\MarketplaceChannel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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

        // Seed feed file rows for the admin "Public Feeds" UI.
        // (Hardcoded defaults so the intended Pigu feeds are always discoverable.)
        $productFilename = 'pigu-products.xml';
        $productPath = 'feeds/pigu-products.xml';
        $productPublicUrl = 'https://hub.gudle.lt/feeds/pigu-products.xml';

        $offersFilename = 'pigu-offers.xml';
        $offersPath = 'feeds/pigu-offers.xml';
        $offersPublicUrl = 'https://hub.gudle.lt/feeds/pigu-offers.xml';

        $now = now();

        DB::table('feed_files')->updateOrInsert(
            [
                'marketplace_channel_id' => $channel->id,
                'filename' => $productFilename,
            ],
            [
                'path' => $productPath,
                'public_url' => $productPublicUrl,
                'status' => FeedFileStatus::Generated->value,
                'generated_at' => $now,
                'created_at' => DB::table('feed_files')
                    ->where('marketplace_channel_id', $channel->id)
                    ->where('filename', $productFilename)
                    ->value('created_at') ?: $now,
                'updated_at' => $now,
            ],
        );

        DB::table('feed_files')->updateOrInsert(
            [
                'marketplace_channel_id' => $channel->id,
                'filename' => $offersFilename,
            ],
            [
                'path' => $offersPath,
                'public_url' => $offersPublicUrl,
                'status' => FeedFileStatus::Generated->value,
                'generated_at' => $now,
                'created_at' => DB::table('feed_files')
                    ->where('marketplace_channel_id', $channel->id)
                    ->where('filename', $offersFilename)
                    ->value('created_at') ?: $now,
                'updated_at' => $now,
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
