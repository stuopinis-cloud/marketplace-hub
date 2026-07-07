<?php

namespace App\Services\Shopify;

class ShopifyImportOptions
{
    public function __construct(
        public readonly ?int $limit = null,
        public readonly ?string $handle = null,
        public readonly bool $verbose = false,
        public readonly bool $force = false,
        public readonly ?\Closure $progressCallback = null,
    ) {}
}
