<?php

namespace App\Console\Commands;

use App\Services\Marketplace\Pigu\PiguExportOptions;
use App\Services\Marketplace\Pigu\PiguOfferFeedExporter;
use Illuminate\Console\Command;
use Throwable;

class PiguExportOffersXmlCommand extends Command
{
    protected $signature = 'pigu:export-offers-xml
                            {--easy-only : Export offers only for easy-first-wave products}';

    protected $description = 'Export the separate Pigu/PHH stock-and-prices (offers) XML feed';

    public function handle(PiguOfferFeedExporter $exporter): int
    {
        $options = PiguExportOptions::fromArray([
            'easy_only' => (bool) $this->option('easy-only'),
        ]);

        try {
            $result = $exporter->export($options);
        } catch (Throwable $exception) {
            $this->components->error('Pigu offers XML export failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Pigu offers XML export completed.');
        $this->line('Public path: '.$result['absolute_path']);
        $this->line('Public URL: '.$result['public_url']);
        $this->line('Offers exported: '.$result['offers_exported']);
        $this->line('Offers skipped: '.$result['offers_skipped']);

        return self::SUCCESS;
    }
}
