<?php

namespace App\Services\Suppliers\Mtac;

class MtacSupplierSyncResult
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
    ) {}
}
