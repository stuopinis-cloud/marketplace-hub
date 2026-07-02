<?php

namespace App\Jobs;

use App\Services\Shopify\ShopifyProductImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportShopifyProductsJob implements ShouldQueue
{
    use Queueable;

    public function handle(ShopifyProductImporter $importer): void
    {
        $importer->import();
    }
}
