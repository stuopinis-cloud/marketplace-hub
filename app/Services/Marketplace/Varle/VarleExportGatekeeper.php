<?php

namespace App\Services\Marketplace\Varle;

use App\Enums\VarleExportStatus;
use App\Models\CategoryMapping;
use App\Models\MarketplaceChannel;
use App\Models\Product;
use App\Services\Marketplace\CategoryResolver;
use Illuminate\Support\Collection;

class VarleExportGatekeeper
{
    public function __construct(
        private readonly CategoryResolver $categoryResolver,
    ) {}

    /**
     * @param  array{
     *     resolved_category: ?string,
     *     source: ?string,
     *     matched_mapping_id: ?int,
     *     matched_source_type: ?string,
     *     matched_source_value: ?string,
     *     fallback_used: bool,
     *     details: array<string, mixed>
     * }|null  $categoryExplanation
     * @param  Collection<int, CategoryMapping>|null  $preloadedMappings
     */
    public function assess(
        Product $product,
        MarketplaceChannel $channel,
        ?array $categoryExplanation = null,
        ?Collection $preloadedMappings = null,
    ): VarleExportGateResult {
        $status = $product->varle_export_status ?? VarleExportStatus::PendingReview;

        return match ($status) {
            VarleExportStatus::PendingReview => VarleExportGateResult::deny('Product pending Varle review'),
            VarleExportStatus::Exclude => VarleExportGateResult::deny('Product excluded from Varle export'),
            VarleExportStatus::Include => $this->allowWithExplanation(
                $product,
                $channel,
                ignoreCategoryExportDisabled: true,
                categoryExplanation: $categoryExplanation,
                preloadedMappings: $preloadedMappings,
            ),
            VarleExportStatus::Auto => $this->assessAuto($product, $channel, $categoryExplanation, $preloadedMappings),
        };
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
     * }|null  $categoryExplanation
     * @param  Collection<int, CategoryMapping>|null  $preloadedMappings
     */
    private function assessAuto(
        Product $product,
        MarketplaceChannel $channel,
        ?array $categoryExplanation = null,
        ?Collection $preloadedMappings = null,
    ): VarleExportGateResult {
        $explanation = $categoryExplanation ?? $this->categoryResolver->explain($product, $channel);
        $mappingExportEnabled = $this->categoryMappingExportEnabled($explanation, $preloadedMappings);

        if ($explanation['matched_mapping_id'] !== null && $mappingExportEnabled === false) {
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
     * }|null  $categoryExplanation
     * @param  Collection<int, CategoryMapping>|null  $preloadedMappings
     */
    private function allowWithExplanation(
        Product $product,
        MarketplaceChannel $channel,
        bool $ignoreCategoryExportDisabled,
        ?array $categoryExplanation = null,
        ?Collection $preloadedMappings = null,
    ): VarleExportGateResult {
        $explanation = $categoryExplanation ?? $this->categoryResolver->explain($product, $channel);
        $mappingExportEnabled = $this->categoryMappingExportEnabled($explanation, $preloadedMappings);

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
     * @param  Collection<int, CategoryMapping>|null  $preloadedMappings
     */
    private function categoryMappingExportEnabled(array $explanation, ?Collection $preloadedMappings = null): ?bool
    {
        if ($explanation['matched_mapping_id'] === null) {
            return null;
        }

        if ($preloadedMappings !== null) {
            $mapping = $preloadedMappings->firstWhere('id', $explanation['matched_mapping_id']);

            return $mapping instanceof CategoryMapping ? (bool) $mapping->export_enabled : null;
        }

        return CategoryMapping::query()
            ->whereKey($explanation['matched_mapping_id'])
            ->value('export_enabled');
    }
}
