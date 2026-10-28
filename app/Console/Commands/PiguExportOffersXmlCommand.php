<?php

namespace App\Console\Commands;

use App\Services\Marketplace\Pigu\PiguExportOptions;
use App\Services\Marketplace\Pigu\PiguOfferFeedExporter;
use App\Services\Marketplace\Pigu\PiguProductXmlValidator;
use Illuminate\Console\Command;
use Throwable;

class PiguExportOffersXmlCommand extends Command
{
    protected $signature = 'pigu:export-offers-xml
                            {--easy-only : Export offers only for easy-first-wave products}
                            {--all-valid : Export all variants with valid sku/ean/price/stock (no category/product readiness required)}';

    protected $description = 'Export the separate Pigu/PHH stock-and-prices (offers) XML feed';

    public function handle(PiguOfferFeedExporter $exporter): int
    {
        $options = PiguExportOptions::fromArray([
            'easy_only' => (bool) $this->option('easy-only'),
            'all_valid' => (bool) $this->option('all-valid'),
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
        $this->line('Filter easy-only: '.(($result['filter']['easy_only'] ?? false) ? 'yes' : 'no'));
        $this->line('Filter all-valid: '.(($result['filter']['all_valid'] ?? false) ? 'yes' : 'no'));
        $this->line('Variants considered: '.$result['variants_considered']);
        $this->line('Offers exported: '.$result['offers_exported']);
        $this->line('Offers skipped: '.$result['offers_skipped']);
        $this->line('Local offers: '.$result['local_offers_count']);
        $this->line('Supplier offers: '.$result['supplier_offers_count']);
        $this->line('Supplier-only offers: '.($result['supplier_only_offers_count'] ?? 0));
        $this->line('Backorder offers: '.$result['backorder_offers_count']);
        $this->line('Unique SKU count: '.$result['unique_sku_count']);
        $this->line('Unique EAN count: '.$result['unique_ean_count']);
        $this->line('Duplicate EAN count: '.$result['duplicate_ean_count']);
        $this->line('Failed CSV: storage/app/public/'.$result['failed_csv_path']);

        $this->newLine();
        $this->line('Skipped by reason:');
        if (($result['skipped_by_code'] ?? []) === []) {
            $this->line('  (none)');
        } else {
            foreach ($result['skipped_by_code'] as $code => $count) {
                $this->line("  {$code}: {$count}");
            }
        }

        $this->newLine();
        $this->line('Offers by collectionhours:');
        if (($result['offers_by_collectionhours'] ?? []) === []) {
            $this->line('  (none)');
        } else {
            foreach ($result['offers_by_collectionhours'] as $hours => $count) {
                $this->line("  {$hours}: {$count}");
            }
        }

        if ($this->output->isVerbose()) {
            $this->newLine();
            $this->components->info('Verbose summary:');
            $this->line('  considered variants: '.$result['variants_considered']);
            $this->line('  exported offers: '.$result['offers_exported']);
            $this->line('  skipped offers: '.$result['offers_skipped']);
            $this->line('  stock sources: local='.$result['local_offers_count']
                .' supplier='.$result['supplier_offers_count']
                .' backorder='.$result['backorder_offers_count']);
            $this->line('  unique SKUs: '.$result['unique_sku_count']);
            $this->line('  unique EANs: '.$result['unique_ean_count']);
            $this->line('  duplicate EANs: '.$result['duplicate_ean_count']);
        }

        $offersValidation = app(PiguProductXmlValidator::class)
            ->validateOffersFeed($result['feed_path']);

        if (! $offersValidation['valid']) {
            $this->components->error('Offers feed validation failed (collectionhours must be integer > 0).');
            foreach ($offersValidation['errors'] as $error) {
                $this->line('  - '.$error['type'].': '.$error['message']);
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
