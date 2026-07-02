<?php

namespace App\Console\Commands;

use App\Jobs\ImportShopifyProductsJob;
use App\Services\Shopify\ShopifyProductImporter;
use Illuminate\Console\Command;
use Throwable;

class ShopifyImportProductsCommand extends Command
{
    protected $signature = 'shopify:import-products {--queue : Dispatch the import to the queue}';

    protected $description = 'Import active products from Shopify into the local catalog';

    public function handle(ShopifyProductImporter $importer): int
    {
        if ($this->option('queue')) {
            ImportShopifyProductsJob::dispatch();
            $this->components->info('Shopify product import dispatched to the queue.');

            return self::SUCCESS;
        }

        try {
            $result = $importer->import();
        } catch (Throwable $exception) {
            $this->components->error('Shopify product import failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Shopify product import completed.');
        $this->line('Sync job ID: '.$result->syncJobId);
        $this->line('Imported products: '.$result->productsImported);
        $this->line('Imported variants: '.$result->variantsImported);
        $this->line('Failed items: '.$result->failedItems);

        return $result->failedItems > 0 ? self::FAILURE : self::SUCCESS;
    }
}
