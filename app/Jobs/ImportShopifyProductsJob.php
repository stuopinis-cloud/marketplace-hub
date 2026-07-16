<?php

namespace App\Jobs;

use App\Services\Sync\MarketplaceJobLock;
use App\Services\Shopify\ShopifyProductImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ImportShopifyProductsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function handle(ShopifyProductImporter $importer): void
    {
        $lock = MarketplaceJobLock::make(MarketplaceJobLock::SHOPIFY_IMPORT);

        if (! $lock->get()) {
            return;
        }

        try {
            $importer->import();
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        MarketplaceJobLock::forceRelease(MarketplaceJobLock::SHOPIFY_IMPORT);
    }
}
