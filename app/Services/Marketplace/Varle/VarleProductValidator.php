<?php

namespace App\Services\Marketplace\Varle;

use App\Models\MarketplaceChannel;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Marketplace\CategoryResolver;
use App\Services\Marketplace\MarketplaceValidationResult;
use App\Services\Marketplace\ProductAvailabilityResolver;

class VarleProductValidator
{
    public function __construct(
        private readonly CategoryResolver $categoryResolver,
        private readonly ProductAvailabilityResolver $availabilityResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $channelConfig
     * @param  array{
     *     resolved_category: ?string,
     *     source: ?string,
     *     matched_mapping_id: ?int,
     *     matched_source_type: ?string,
     *     matched_source_value: ?string,
     *     fallback_used: bool,
     *     details: array<string, mixed>
     * }|null  $categoryExplanation
     */
    public function validateProduct(
        Product $product,
        MarketplaceChannel $channel,
        array $channelConfig,
        ?array $categoryExplanation = null,
    ): MarketplaceValidationResult {
        $errors = [];
        $warnings = [];
        $explanation = $categoryExplanation ?? $this->categoryResolver->explain($product, $channel);

        if (blank($product->handle)) {
            $errors[] = 'Product handle is required.';
        }

        if (blank($product->title)) {
            $errors[] = 'Product title is required.';
        }

        if (blank($explanation['resolved_category'])) {
            if ($channelConfig['require_category_mapping'] ?? false) {
                $errors[] = 'Missing required category mapping';
            } else {
                $errors[] = 'Category could not be resolved.';
            }
        }

        if (! VarleVariantPresenter::productHasExportableImages($product, $channelConfig)) {
            $errors[] = VarleVariantPresenter::missingExportImagesMessage($channelConfig);
        }

        if ($this->descriptionIsMissing($product)) {
            $errors[] = 'Description is required.';
        }

        if ($errors !== []) {
            return MarketplaceValidationResult::invalid($errors, $warnings);
        }

        return MarketplaceValidationResult::valid($warnings);
    }

    /**
     * @param  array<string, mixed>  $channelConfig
     * @param  array<string, mixed>|null  $deliveryRule
     */
    public function validateVariant(
        ProductVariant $variant,
        array $channelConfig,
        ?array $deliveryRule = null,
    ): MarketplaceValidationResult {
        $errors = [];

        if (blank($variant->sku)) {
            $errors[] = 'SKU is required.';
        }

        if (blank($variant->barcode)) {
            $errors[] = 'Barcode is required.';
        }

        if ((float) $variant->price <= 0) {
            $errors[] = 'Price must be greater than 0.';
        }

        $variant->loadMissing('inventoryLevels', 'supplierProducts.supplier');
        $availability = $this->availabilityResolver->resolve($variant, $deliveryRule);
        $isBackorderExport = ($availability['source_type'] ?? null) === ProductAvailabilityResolver::SOURCE_BACKORDER;

        if ($variant->inventoryLevels->isEmpty() && ! $isBackorderExport && (int) $availability['quantity'] <= 0) {
            $errors[] = 'Inventory quantity record is required.';
        }

        if (! $availability['exportable'] || (int) $availability['quantity'] <= 0) {
            $errors[] = 'Resolved quantity must be greater than 0.';
        }

        if ($errors !== []) {
            return MarketplaceValidationResult::invalid($errors);
        }

        return MarketplaceValidationResult::valid();
    }

    private function descriptionIsMissing(Product $product): bool
    {
        if (blank($product->description_html)) {
            return true;
        }

        return blank(trim(strip_tags((string) $product->description_html)));
    }
}
