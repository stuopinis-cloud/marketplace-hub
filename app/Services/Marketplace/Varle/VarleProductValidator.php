<?php

namespace App\Services\Marketplace\Varle;

use App\Models\MarketplaceChannel;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Marketplace\CategoryResolver;
use App\Services\Marketplace\MarketplaceValidationResult;

class VarleProductValidator
{
    public function __construct(
        private readonly CategoryResolver $categoryResolver,
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
     */
    public function validateVariant(
        ProductVariant $variant,
        array $channelConfig,
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

        if ($variant->inventoryLevels->isEmpty()) {
            $errors[] = 'Inventory quantity record is required.';
        }

        if (! ($channelConfig['export_zero_stock'] ?? true) && $this->sumInventoryQuantity($variant) <= 0) {
            $errors[] = 'Variant skipped because export_zero_stock is disabled and quantity is zero.';
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

    private function sumInventoryQuantity(ProductVariant $variant): int
    {
        return (int) $variant->inventoryLevels->sum('quantity');
    }
}
