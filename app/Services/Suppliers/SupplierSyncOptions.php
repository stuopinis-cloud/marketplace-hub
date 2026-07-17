<?php

namespace App\Services\Suppliers;

class SupplierSyncOptions
{
    /**
     * @param  list<string>|null  $only
     */
    public function __construct(
        public readonly bool $dryRun = false,
        public readonly ?int $limit = null,
        public readonly ?string $sku = null,
        public readonly bool $verbose = false,
        public readonly bool $force = false,
        public readonly ?array $only = null,
    ) {}

    public function isPartialRun(): bool
    {
        return $this->limit !== null || filled($this->sku);
    }
}
