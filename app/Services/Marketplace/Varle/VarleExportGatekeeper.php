<?php

namespace App\Services\Marketplace\Varle;

use App\Enums\VarleExportStatus;
use App\Models\CategoryMapping;
use App\Models\MarketplaceChannel;
use App\Models\Product;
use App\Services\Marketplace\CategoryResolver;

class VarleExportGatekeeper
{
    public function __construct(
        private readonly CategoryResolver $categoryResolver,
    ) {}

    public function assess(Product $product, MarketplaceChannel $channel): VarleExportGateResult
    {
        $status = $product->varle_export_status ?? VarleExportStatus::PendingReview;

        return match ($status) {
            VarleExportStatus::PendingReview => VarleExportGateResult::deny('Product pending Varle review'),
            VarleExportStatus::Exclude => VarleExportGateResult::deny('Product excluded from Varle export'),
            VarleExportStatus::Include => $this->allowWithExplanation($product, $channel, ignoreCategoryExportDisabled: true),
            VarleExportStatus::Auto => $this->assessAuto($product, $channel),
        };
    }

    private function assessAuto(Product $product, MarketplaceChannel $channel): VarleExportGateResult
    {
        $explanation = $this->categoryResolver->explain($product, $channel);
        $mappingExportEnabled = $this->categoryMappingExportEnabled($explanation);

        if ($explanation['matched_mapping_id'] !== null && $mappingExportEnabled === false) {
            return VarleExportGateResult::deny(
                'Category mapping disabled for Varle export',
                $explanation,
                false,
            );
        }

        return VarleExportGateResult::allow($explanation, $mappingExportEnabled);
    }

    private function allowWithExplanation(
        Product $product,
        MarketplaceChannel $channel,
        bool $ignoreCategoryExportDisabled,
    ): VarleExportGateResult {
        $explanation = $this->categoryResolver->explain($product, $channel);
        $mappingExportEnabled = $this->categoryMappingExportEnabled($explanation);

        if (! $ignoreCategoryExportDisabled
            && $explanation['matched_mapping_id'] !== null
            && $mappingExportEnabled === false) {
            return VarleExportGateResult::deny(
                'Category mapping disabled for Varle export',
                $explanation,
                false,
            );
        }

        return VarleExportGateResult::allow($explanation, $mappingExportEnabled);
    }

    /**
     * @param  array{
     *     resolved_category: ?string,
     *     source: ?string,
     *     matched_mapping_id: ?int,
     *     matched_source_type: ?string,
     *     matched_source_value: ?string,
     *     fallback_used: bool,
     *     details: array<string, mixed>
     * }  $explanation
     */
    private function categoryMappingExportEnabled(array $explanation): ?bool
    {
        if ($explanation['matched_mapping_id'] === null) {
            return null;
        }

        return CategoryMapping::query()
            ->whereKey($explanation['matched_mapping_id'])
            ->value('export_enabled');
    }
}
