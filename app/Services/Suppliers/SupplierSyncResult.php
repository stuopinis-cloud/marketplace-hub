<?php

namespace App\Services\Suppliers;

class SupplierSyncResult
{
    public function __construct(
        public readonly int $syncJobId,
        public readonly int $parsed,
        public readonly int $matched,
        public readonly int $unmatched,
        public readonly int $ambiguous,
        public readonly int $duplicateSupplierSku,
        public readonly int $positiveStock,
        public readonly int $zeroStock,
        public readonly int $failedRows,
        public readonly int $missingFromFeed,
        public readonly int $duplicateShopifySku = 0,
        public readonly int $missingSku = 0,
        public readonly int $missingQuantity = 0,
    ) {}
}
