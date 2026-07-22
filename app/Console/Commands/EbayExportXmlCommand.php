<?php

namespace App\Console\Commands;

use App\Services\Marketplace\Ebay\EbayFeedExporter;
use Illuminate\Console\Command;

class EbayExportXmlCommand extends Command
{
    protected $signature = 'ebay:export-xml
                            {--locale=en : Export locale}';

    protected $description = 'Export the translation-ready eBay XML feed skeleton';

    public function handle(EbayFeedExporter $exporter): int
    {
        $locale = (string) $this->option('locale');
        $result = $exporter->export($locale);

        $this->components->info(sprintf(
            'eBay feed exported to %s (%d products, %d variants, %d skipped).',
            $result['feed_path'],
            $result['exported_products'],
            $result['exported_variants'],
            $result['skipped_products'],
        ));

        return self::SUCCESS;
    }
}
