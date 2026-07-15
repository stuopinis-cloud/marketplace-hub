<?php

namespace App\Services\Marketplace;

class CategoryBulkApprovalResult
{
    /**
     * @param  array<int, int>  $productIds
     */
    public function __construct(
        public readonly int $updatedCount,
        public readonly array $productIds,
        public readonly ?int $readinessSyncJobId = null,
        public readonly bool $readinessQueued = false,
    ) {}
}
