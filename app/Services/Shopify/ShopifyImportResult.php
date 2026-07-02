<?php

namespace App\Services\Shopify;

class ShopifyImportResult
{
    public function __construct(
        public readonly int $syncJobId,
        public readonly int $productsImported = 0,
        public readonly int $variantsImported = 0,
        public readonly int $failedItems = 0,
        public readonly int $newProductsCount = 0,
        public readonly int $updatedProductsCount = 0,
        public readonly int $pendingReviewProductsCount = 0,
        public readonly int $unpublishedProductsCount = 0,
    ) {}
}
