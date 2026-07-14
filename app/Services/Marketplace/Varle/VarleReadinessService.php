<?php

namespace App\Services\Marketplace\Varle;

use App\Enums\ProductStatus;
use App\Enums\VarleExportStatus;
use App\Models\CategoryMapping;
use App\Models\MarketplaceChannel;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\MarketplaceChannelConfig;
use App\Services\Marketplace\CategoryResolver;
use App\Services\Marketplace\ProductAvailabilityResolver;

class VarleReadinessService
{
    public function __construct(
        private readonly CategoryResolver $categoryResolver,
        private readonly VarleExportGatekeeper $exportGatekeeper,
        private readonly VarleProductValidator $validator,
        private readonly VarleDeliveryResolver $deliveryResolver,
        private readonly VarleStockEvaluator $stockEvaluator,
        private readonly ProductAvailabilityResolver $availabilityResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function analyze(Product $product, ?MarketplaceChannel $channel = null, ?VarleReadinessRunContext $context = null): array
    {
        $product->loadMissing(['variants.inventoryLevels', 'variants.supplierProducts.supplier', 'images', 'sourceCategories']);

        $context ??= $this->createRunContext();
        $channel = $context->channel;
        $channelConfig = $context->channelConfig;
        $config = MarketplaceChannelConfig::for($channelConfig);
        $deliveryRule = $this->deliveryResolver->resolveForProductPreloaded($product, $channelConfig, [
            'rules_by_vendor' => $context->vendorRulesByVendor,
            'default_rule' => $context->defaultVendorRule,
        ]);
        $categoryExplanation = $this->categoryResolver->explain($product, $channel, $context->categoryMappings);
        $gate = $this->exportGatekeeper->assess(
            $product,
            $channel,
            $categoryExplanation,
            $context->categoryMappings,
        );

        $issueCodes = [];
        $issueMessages = [];
        $variantDiagnostics = [];
        $exportableVariants = 0;
        $skippedVariants = 0;
        $deliveryClasses = [];

        if ($product->varle_export_status === VarleExportStatus::PendingReview) {
            $issueCodes[] = 'pending_review';
            $issueMessages[] = 'Product pending Varle review.';
        }

        if ($product->varle_export_status === VarleExportStatus::Exclude) {
            $issueCodes[] = 'excluded';
            $issueMessages[] = 'Product excluded from Varle export.';
        }

        if ($product->status !== ProductStatus::Active) {
            $issueCodes[] = 'unpublished';
            $issueMessages[] = 'Product is not published in Shopify.';
        }

        $categoryStatus = $this->resolveCategoryStatus($categoryExplanation);
        if ($categoryStatus === 'missing') {
            $issueCodes[] = 'missing_category_mapping';
            $issueMessages[] = 'Category mapping is missing.';
        }

        $barcodeStatus = $this->resolveBarcodeStatus($product);
        if ($barcodeStatus === 'some_variants_missing_barcode' || $barcodeStatus === 'no_barcodes') {
            $issueCodes[] = 'missing_barcode';
            $issueMessages[] = 'One or more variants are missing a barcode.';
        }

        $imageStatus = $this->resolveImageStatus($product, $config);
        if ($imageStatus === 'some_exportable_variants_missing_image' || $imageStatus === 'no_variant_images' || $imageStatus === 'no_images') {
            $issueCodes[] = $imageStatus === 'no_images' ? 'no_images' : 'missing_variant_image';
            $issueMessages[] = VarleVariantPresenter::missingExportImagesMessage($channelConfig);
        }

        $isSimpleProduct = VarleVariantPresenter::isSimpleShopifyProduct($product);

        if (($deliveryRule['enabled'] ?? true) === false) {
            $issueCodes[] = 'vendor_disabled_for_varle';
            $issueMessages[] = 'Vendor delivery rule is disabled.';
        }

        $productValidation = $gate->allowed
            ? $this->validator->validateProduct($product, $channel, $channelConfig, $categoryExplanation)
            : null;

        foreach ($product->variants as $variant) {
            $availability = $this->availabilityResolver->resolve($variant, $deliveryRule);
            $variantIssues = [];
            $exportable = true;
            $skipReason = null;
            $issueCode = null;

            if (blank($variant->sku)) {
                $exportable = false;
                $issueCode = 'price_invalid';
                $skipReason = 'SKU is required.';
            }

            if (blank($variant->barcode)) {
                $exportable = false;
                $issueCode = 'missing_barcode';
                $skipReason = 'Barcode is required.';
            }

            if ((float) $variant->price <= 0) {
                $exportable = false;
                $issueCode = 'price_invalid';
                $skipReason = 'Price must be greater than 0.';
            }

            if ($variant->inventoryLevels->isEmpty() && (int) $availability['quantity'] <= 0) {
                $exportable = false;
                $issueCode = 'no_exportable_variants';
                $skipReason = 'Inventory quantity record is required.';
            }

            if ($availability['is_stale'] && ($availability['supplier_quantity'] ?? 0) > 0 && $availability['local_quantity'] <= 0) {
                if (! in_array('supplier_stock_stale', $issueCodes, true)) {
                    $issueCodes[] = 'supplier_stock_stale';
                    $issueMessages[] = 'Supplier stock is stale.';
                }
            }

            if (! $availability['exportable'] || (int) $availability['quantity'] <= 0) {
                $exportable = false;
                $issueCode = $availability['issue_code'];
                $skipReason = $availability['issue_message'];
            } elseif ($exportable) {
                $deliveryClasses[] = $availability['delivery_class'];
            }

            if ($exportable && $gate->allowed) {
                if ($productValidation !== null && ! $productValidation->isValid) {
                    $exportable = false;
                    $skipReason = $productValidation->message();
                }
            } elseif (! $gate->allowed) {
                $exportable = false;
                $skipReason = $gate->skipMessage;
            }

            if ($exportable) {
                $groupRows = [['variant' => $variant, 'quantity' => $availability['quantity']]];
                $images = VarleVariantPresenter::resolveExportImageUrls(
                    $product,
                    $groupRows,
                    $channelConfig,
                    $isSimpleProduct,
                );
                if ($images['urls'] === []) {
                    $exportable = false;
                    $issueCode = 'missing_variant_image';
                    $skipReason = VarleVariantPresenter::missingExportImagesMessage($channelConfig);
                }
            }

            if ($exportable) {
                $exportableVariants++;
            } else {
                $skippedVariants++;
                if ($issueCode !== null && ! in_array($issueCode, $issueCodes, true)) {
                    $issueCodes[] = $issueCode;
                }
                if ($skipReason !== null) {
                    $issueMessages[] = $skipReason;
                    $variantIssues[] = $skipReason;
                }
            }

            $variantDiagnostics[] = [
                'variant_id' => $variant->id,
                'sku' => $variant->sku,
                'title' => $variant->title,
                'barcode' => $variant->barcode,
                'price' => $variant->price,
                'compare_at_price' => $variant->compare_at_price,
                'quantity' => $availability['quantity'],
                'local_quantity' => $availability['local_quantity'],
                'supplier_quantity' => $availability['supplier_quantity'],
                'supplier_availability' => $availability['supplier_availability'],
                'used_availability_fallback' => $availability['used_availability_fallback'],
                'availability_fallback_quantity' => $availability['availability_fallback_quantity'],
                'availability_source' => $availability['source_type'],
                'resolved_quantity' => $availability['quantity'],
                'resolved_delivery_text' => $availability['delivery_text'],
                'supplier_name' => $availability['supplier_name'],
                'supplier_stock_stale' => $availability['is_stale'],
                'supplier_match_status' => $availability['supplier_match_status'],
                'inventory_policy' => $variant->inventory_policy,
                'backorder_allowed' => $variant->backorder_allowed,
                'has_variant_image' => filled($variant->image_url),
                'image_url' => $variant->image_url,
                'exportable' => $exportable,
                'issue_code' => $issueCode,
                'skipped_reason' => $skipReason,
                'delivery_class' => $availability['delivery_class'],
                'issues' => $variantIssues,
            ];
        }

        $stockStatus = $this->resolveStockStatus($product, $deliveryRule, $variantDiagnostics);
        if (in_array($stockStatus, ['out_of_stock_blocked', 'no_exportable_stock'], true)) {
            if (! in_array('out_of_stock_no_backorder', $issueCodes, true) && ! in_array('backorder_disabled_for_vendor', $issueCodes, true)) {
                if ($exportableVariants === 0 && $product->variants->isNotEmpty()) {
                    $issueCodes[] = 'no_exportable_variants';
                    $issueMessages[] = 'No exportable variants remain after stock and validation checks.';
                }
            }
        }

        if (! $gate->allowed && ! in_array($this->gateIssueCode($gate->skipMessage), $issueCodes, true)) {
            $issueCodes[] = $this->gateIssueCode($gate->skipMessage);
            $issueMessages[] = (string) $gate->skipMessage;
        }

        $issueCodes = array_values(array_unique($issueCodes));
        $issueMessages = array_values(array_unique(array_filter($issueMessages)));
        $deliveryTextPreview = $this->deliveryResolver->resolveProductDeliveryText(
            $deliveryRule,
            $deliveryClasses !== [] ? $deliveryClasses : [VarleStockEvaluator::CLASS_IN_STOCK],
            collect($variantDiagnostics)
                ->where('exportable', true)
                ->pluck('resolved_delivery_text')
                ->filter()
                ->values()
                ->all(),
        );

        $isReady = $gate->allowed
            && $exportableVariants > 0
            && ! in_array('missing_category_mapping', $issueCodes, true)
            && ! in_array('no_images', $issueCodes, true)
            && ! in_array('missing_variant_image', $issueCodes, true)
            && ! in_array('pending_review', $issueCodes, true)
            && ! in_array('excluded', $issueCodes, true)
            && ! in_array('unpublished', $issueCodes, true);

        $exportableRows = collect($variantDiagnostics)
            ->filter(fn (array $row): bool => $row['exportable'])
            ->map(fn (array $row): array => [
                'variant' => $product->variants->firstWhere('id', $row['variant_id']),
                'quantity' => $row['quantity'],
            ])
            ->filter(fn (array $row): bool => $row['variant'] instanceof ProductVariant)
            ->values()
            ->all();

        $exportStructure = $this->resolveExportStructure($product, $exportableRows);

        $imageResolution = $exportableVariants > 0
            ? VarleVariantPresenter::resolveExportImageUrls(
                $product,
                $exportableRows,
                $channelConfig,
                ! $exportStructure['will_generate_variants_block'],
            )
            : ['urls' => [], 'variant_images_count' => 0, 'generic_gallery_images_count' => 0, 'forbidden_variant_images_count' => 0];

        return [
            'is_ready_for_varle' => $isReady,
            'issue_count' => count($issueCodes),
            'issue_codes' => $issueCodes,
            'issue_messages' => $issueMessages,
            'barcode_status' => $barcodeStatus,
            'image_status' => $imageStatus,
            'category_status' => $categoryStatus,
            'stock_status' => $stockStatus,
            'vendor_delivery_rule_status' => $deliveryRule['status'],
            'delivery_text_preview' => $deliveryTextPreview,
            'mapped_category_preview' => $categoryExplanation['resolved_category'] ?? null,
            'exportable_variants_count' => $exportableVariants,
            'skipped_variants_count' => $skippedVariants,
            'delivery_rule' => $deliveryRule,
            'category_explanation' => $categoryExplanation,
            'gate_allowed' => $gate->allowed,
            'gate_message' => $gate->skipMessage,
            'variant_diagnostics' => $variantDiagnostics,
            'image_resolution' => $imageResolution,
            'generated_product_ids' => $this->previewGeneratedProductIds($product, $variantDiagnostics),
            'export_structure' => $exportStructure['export_structure'],
            'meaningful_options' => $exportStructure['meaningful_options'],
            'shopify_total_variants' => $exportStructure['shopify_total_variants'],
            'included_variants_count' => $exportStructure['included_variants_count'],
            'will_generate_variants_block' => $exportStructure['will_generate_variants_block'],
            'export_groups' => $exportStructure['export_groups'],
            'is_simple_shopify_product' => $isSimpleProduct,
        ];
    }

    public function cache(Product $product, ?array $analysis = null, ?VarleReadinessRunContext $context = null): Product
    {
        $analysis ??= $this->analyze($product, context: $context);

        $product->update([
            'varle_is_ready' => $analysis['is_ready_for_varle'],
            'varle_issue_count' => $analysis['issue_count'],
            'varle_issue_codes' => $analysis['issue_codes'],
            'varle_barcode_status' => $analysis['barcode_status'],
            'varle_image_status' => $analysis['image_status'],
            'varle_category_status' => $analysis['category_status'],
            'varle_stock_status' => $analysis['stock_status'],
            'varle_vendor_delivery_rule_status' => $analysis['vendor_delivery_rule_status'],
            'varle_delivery_text_preview' => $analysis['delivery_text_preview'],
            'varle_mapped_category_preview' => $analysis['mapped_category_preview'],
            'varle_exportable_variants_count' => $analysis['exportable_variants_count'],
            'varle_skipped_variants_count' => $analysis['skipped_variants_count'],
            'varle_readiness_cached_at' => now(),
        ]);

        return $product;
    }

    public function createRunContext(): VarleReadinessRunContext
    {
        $channel = $this->resolveChannel();
        $channelConfig = $this->channelConfig($channel);
        $preloadedRules = $this->deliveryResolver->preloadRules();

        return new VarleReadinessRunContext(
            channel: $channel,
            channelConfig: $channelConfig,
            categoryMappings: CategoryMapping::query()
                ->where('marketplace_channel_id', $channel->id)
                ->where('enabled', true)
                ->get(),
            vendorRulesByVendor: $preloadedRules['rules_by_vendor'],
            defaultVendorRule: $preloadedRules['default_rule'],
        );
    }

    public function refreshAll(int $chunkSize = 100, ?array $productIds = null): int
    {
        return app(VarleReadinessRefreshService::class)->runSynchronously($chunkSize, $productIds);
    }

    private function resolveChannel(): MarketplaceChannel
    {
        return MarketplaceChannel::query()->firstOrCreate(
            ['type' => 'varle', 'name' => 'Varle.lt'],
            ['enabled' => true, 'config' => []],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function channelConfig(MarketplaceChannel $channel): array
    {
        return MarketplaceChannelConfig::merge($channel->config ?? [], [
            'default_category' => 'Kita',
            'export_zero_stock' => true,
            'price_multiplier' => 1,
            'feed_filename' => 'varle.xml',
            'require_category_mapping' => false,
            'allow_fallback_product_images' => false,
            'allow_backorder_export' => true,
            'delivery_in_stock_text' => (string) config('marketplace.exports.varle.default_delivery_text', '1-2 d.d.'),
            'delivery_backorder_text' => '5-10 d.d.',
        ]);
    }

    /**
     * @param  array{
     *     resolved_category: ?string,
     *     fallback_used: bool
     * }  $categoryExplanation
     */
    private function resolveCategoryStatus(array $categoryExplanation): string
    {
        if (blank($categoryExplanation['resolved_category'])) {
            return 'missing';
        }

        return ($categoryExplanation['fallback_used'] ?? false) ? 'fallback' : 'mapped';
    }

    private function resolveBarcodeStatus(Product $product): string
    {
        if ($product->variants->isEmpty()) {
            return 'not_applicable';
        }

        $withBarcode = $product->variants->filter(fn (ProductVariant $variant): bool => filled($variant->barcode))->count();

        return match (true) {
            $withBarcode === 0 => 'no_barcodes',
            $withBarcode < $product->variants->count() => 'some_variants_missing_barcode',
            default => 'all_variants_have_barcode',
        };
    }

    private function resolveImageStatus(Product $product, MarketplaceChannelConfig $config): string
    {
        if ($product->variants->isEmpty()) {
            return $product->images->isNotEmpty() ? 'has_fallback_images' : 'no_images';
        }

        if (VarleVariantPresenter::isSimpleShopifyProduct($product)) {
            $variant = $product->variants->first();

            if ($variant instanceof ProductVariant && filled($variant->image_url)) {
                return 'all_exportable_variants_have_image';
            }

            return $product->images->isNotEmpty()
                ? 'has_fallback_images'
                : 'no_images';
        }

        $withImage = $product->variants->filter(fn (ProductVariant $variant): bool => filled($variant->image_url))->count();

        if ($withImage === 0) {
            return $config->bool('allow_fallback_product_images') && $product->images->isNotEmpty()
                ? 'has_fallback_images'
                : 'no_variant_images';
        }

        if ($withImage < $product->variants->count()) {
            return 'some_exportable_variants_missing_image';
        }

        return 'all_exportable_variants_have_image';
    }

    /**
     * @param  array<int, array<string, mixed>>  $variantDiagnostics
     */
    private function resolveStockStatus(Product $product, array $deliveryRule, array $variantDiagnostics): string
    {
        if ($product->variants->isEmpty()) {
            return 'no_exportable_stock';
        }

        $exportable = collect($variantDiagnostics)->where('exportable', true);
        if ($exportable->isEmpty()) {
            $blocked = collect($variantDiagnostics)->contains(fn (array $row): bool => in_array($row['issue_code'], ['out_of_stock_no_backorder', 'backorder_disabled_for_vendor'], true));

            return $blocked ? 'out_of_stock_blocked' : 'no_exportable_stock';
        }

        $classes = $exportable->pluck('delivery_class')->unique()->values();

        if ($classes->contains(VarleStockEvaluator::CLASS_BACKORDER) && $classes->contains(VarleStockEvaluator::CLASS_IN_STOCK)) {
            return 'mixed_stock_backorder';
        }

        if ($classes->every(fn (string $class): bool => $class === VarleStockEvaluator::CLASS_BACKORDER)) {
            return 'backorder_only';
        }

        return 'in_stock';
    }

    private function gateIssueCode(?string $message): string
    {
        return match ($message) {
            'Product pending Varle review' => 'pending_review',
            'Product excluded from Varle export' => 'excluded',
            'Category mapping disabled for Varle export' => 'missing_category_mapping',
            default => 'no_exportable_variants',
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $variantDiagnostics
     * @return array<int, string>
     */
    private function previewGeneratedProductIds(Product $product, array $variantDiagnostics): array
    {
        $exportable = collect($variantDiagnostics)->where('exportable', true)->values();

        if ($exportable->isEmpty() || blank($product->handle)) {
            return [];
        }

        $rows = $exportable->map(fn (array $row): array => [
            'variant' => $product->variants->firstWhere('id', $row['variant_id']),
            'quantity' => $row['quantity'],
        ])->filter(fn (array $row): bool => $row['variant'] instanceof ProductVariant)->all();

        $hasColor = collect($rows)->contains(fn (array $row): bool => filled(
            VarleVariantPresenter::colorValue($product, $row['variant']),
        ));

        if (! $hasColor) {
            return [(string) $product->handle];
        }

        return collect($rows)
            ->groupBy(fn (array $row): string => (string) VarleVariantPresenter::colorValue($product, $row['variant']))
            ->keys()
            ->filter()
            ->map(fn (string $color): string => $product->handle.'-'.\Illuminate\Support\Str::slug($color))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{variant: ProductVariant, quantity: int}>  $exportableRows
     * @return array{
     *     export_structure: string,
     *     meaningful_options: array<int, array{name: string, values: array<int, string>}>,
     *     shopify_total_variants: int,
     *     included_variants_count: int,
     *     will_generate_variants_block: bool,
     *     export_groups: array<int, array<string, mixed>>
     * }
     */
    private function resolveExportStructure(Product $product, array $exportableRows): array
    {
        $meaningfulOptions = VarleVariantPresenter::detectMeaningfulOptions($product);

        if ($exportableRows === []) {
            return [
                'export_structure' => VarleVariantPresenter::isSimpleShopifyProduct($product)
                    ? 'simple_product'
                    : 'variant_product',
                'meaningful_options' => $meaningfulOptions,
                'shopify_total_variants' => $product->variants->count(),
                'included_variants_count' => 0,
                'will_generate_variants_block' => false,
                'export_groups' => [],
            ];
        }

        $hasColor = collect($exportableRows)->contains(
            fn (array $row): bool => filled(VarleVariantPresenter::colorValue($product, $row['variant'])),
        );

        $groups = $hasColor
            ? collect($exportableRows)->groupBy(
                fn (array $row): string => (string) VarleVariantPresenter::colorValue($product, $row['variant']),
            )
            : collect(['' => $exportableRows]);

        $exportGroups = [];
        $willGenerateVariantsBlock = false;

        foreach ($groups as $colorValue => $rows) {
            $rows = $rows instanceof \Illuminate\Support\Collection ? $rows->values()->all() : (array) $rows;
            $groupWillOutputVariants = VarleVariantPresenter::shouldOutputVariants($product, $rows);
            $willGenerateVariantsBlock = $willGenerateVariantsBlock || $groupWillOutputVariants;

            $exportGroups[] = [
                'color_value' => filled($colorValue) ? (string) $colorValue : null,
                'variant_count' => count($rows),
                'export_structure' => $groupWillOutputVariants ? 'variant_product' : 'simple_product',
                'will_generate_variants_block' => $groupWillOutputVariants,
            ];
        }

        return [
            'export_structure' => $willGenerateVariantsBlock ? 'variant_product' : 'simple_product',
            'meaningful_options' => $meaningfulOptions,
            'shopify_total_variants' => $product->variants->count(),
            'included_variants_count' => count($exportableRows),
            'will_generate_variants_block' => $willGenerateVariantsBlock,
            'export_groups' => $exportGroups,
        ];
    }
}
