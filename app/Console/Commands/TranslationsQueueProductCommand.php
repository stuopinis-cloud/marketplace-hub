<?php

namespace App\Console\Commands;

use App\Services\Marketplace\Translations\TranslationQueueService;
use Illuminate\Console\Command;

class TranslationsQueueProductCommand extends Command
{
    protected $signature = 'translations:queue-product
                            {product_id : Product ID}
                            {--marketplace=ebay : Marketplace code}
                            {--locale=en : Target locale}';

    protected $description = 'Queue translations for a single product';

    public function handle(TranslationQueueService $queue): int
    {
        $productId = (int) $this->argument('product_id');
        $marketplace = (string) $this->option('marketplace');
        $locale = (string) $this->option('locale');

        $queue->queueProduct($productId, $marketplace, $locale);

        $this->components->info(sprintf(
            'Queued translations for product #%d (%s/%s).',
            $productId,
            $marketplace,
            $locale,
        ));

        return self::SUCCESS;
    }
}
