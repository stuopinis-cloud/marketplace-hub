<?php

namespace App\Services\Marketplace\Varle;

class VarleExportPreviewResult
{
    /**
     * @param  array<string, int>  $topSkipReasons
     */
    public function __construct(
        public readonly int $exportableProducts = 0,
        public readonly int $exportableVariants = 0,
        public readonly int $skippedVariants = 0,
        public readonly int $pendingReviewProducts = 0,
        public readonly int $excludedProducts = 0,
        public readonly int $categoryDisabledProducts = 0,
        public readonly int $unpublishedProducts = 0,
        public readonly int $missingBarcodeVariants = 0,
        public readonly int $missingCategoryProducts = 0,
        public readonly int $fallbackCategoryProducts = 0,
        public readonly array $topSkipReasons = [],
    ) {}
}
