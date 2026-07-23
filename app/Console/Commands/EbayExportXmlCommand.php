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

        $this->components->info('eBay feed export completed.');
        $this->line('Public path: '.$result['absolute_path']);
        $this->line('Public URL: '.$result['public_url']);
        $this->line('Exported products: '.$result['exported_products']);
        $this->line('Exported variants: '.$result['exported_variants']);
        $this->line('Skipped products: '.$result['skipped_products']);

        return self::SUCCESS;
    }
}
