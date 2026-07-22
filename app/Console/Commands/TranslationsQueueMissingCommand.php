<?php

namespace App\Console\Commands;

use App\Services\Marketplace\Translations\TranslationQueueService;
use Illuminate\Console\Command;

class TranslationsQueueMissingCommand extends Command
{
    protected $signature = 'translations:queue-missing
                            {--marketplace=ebay : Marketplace code}
                            {--locale=en : Target locale}
                            {--limit= : Optional product limit}';

    protected $description = 'Queue missing marketplace translations for products';

    public function handle(TranslationQueueService $queue): int
    {
        $marketplace = (string) $this->option('marketplace');
        $locale = (string) $this->option('locale');
        $limit = filled($this->option('limit')) ? (int) $this->option('limit') : null;

        $result = $queue->queueMissingForMarketplace($marketplace, $locale, $limit);

        $this->components->info(sprintf(
            'Queued %d product translation jobs and %d field translation jobs for %s/%s.',
            $result['products_queued'],
            $result['fields_queued'],
            $marketplace,
            $locale,
        ));

        return self::SUCCESS;
    }
}
