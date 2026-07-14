<?php

namespace App\Services\Marketplace\Varle;

use App\Enums\ProductStatus;
use App\Enums\VarleExportStatus;
use App\Filament\Resources\Products\ProductResource;
use App\Models\CategoryMapping;
use App\Models\MarketplaceChannel;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SyncJob;
use App\Models\SyncJobItem;
use App\Services\Marketplace\CategoryResolver;
use Illuminate\Support\Collection;

class VarleReadinessMetrics
{
    public function __construct(
        private readonly CategoryResolver $categoryResolver,
    ) {}

    public function latestShopifyImport(): ?SyncJob
    {
        return SyncJob::query()
            ->where('type', 'import')
            ->where('source', 'shopify')
            ->latest('id')
            ->first();
    }

    public function latestVarleExport(): ?SyncJob
    {
        return SyncJob::query()
            ->where('type', 'export')
            ->where('channel', 'varle')
            ->latest('id')
            ->first();
    }

    public function latestReadinessRefresh(): ?SyncJob
    {
        return SyncJob::query()
            ->where('type', 'readiness')
            ->where('source', 'marketplace')
            ->where('channel', 'varle')
            ->latest('id')
            ->first();
    }

    public function varleChannel(): ?MarketplaceChannel
    {
        return MarketplaceChannel::query()
            ->where('type', 'varle')
            ->first();
    }

    /**
     * @return array{
     *     published_products: int,
     *     unpublished_products: int,
     *     total_variants: int,
     *     variants_missing_barcode: int,
     *     variants_missing_sku: int,
     *     variants_with_invalid_price: int,
     *     products_missing_images: int,
     *     products_missing_category_mapping: int,
     *     products_using_fallback_category: int,
     * }
     */
    public function dataQuality(): array
    {
        $categoryQuality = $this->categoryQualityCounts();

        return [
            'published_products' => Product::query()
                ->where('status', ProductStatus::Active)
                ->count(),
            'unpublished_products' => Product::query()
                ->whereIn('status', [ProductStatus::Draft, ProductStatus::Archived])
                ->count(),
            'total_variants' => ProductVariant::query()->count(),
            'variants_missing_barcode' => ProductVariant::query()
                ->where(fn ($query) => $query->whereNull('barcode')->orWhere('barcode', ''))
                ->count(),
            'variants_missing_sku' => ProductVariant::query()
                ->where(fn ($query) => $query->whereNull('sku')->orWhere('sku', ''))
                ->count(),
            'variants_with_invalid_price' => ProductVariant::query()
                ->where('price', '<=', 0)
                ->count(),
            'products_missing_images' => Product::query()
                ->doesntHave('images')
                ->count(),
            'products_missing_category_mapping' => $categoryQuality['missing_category'],
            'products_using_fallback_category' => $categoryQuality['using_fallback'],
        ];
    }

    /**
     * @return Collection<int, SyncJobItem>
     */
    public function recentExportProblems(int $limit = 50): Collection
    {
        $export = $this->latestVarleExport();

        if ($export === null) {
            return collect();
        }

        return SyncJobItem::query()
            ->where('sync_job_id', $export->id)
            ->with(['product', 'variant'])
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    public function publicFeedUrl(?SyncJob $export = null): string
    {
        $export ??= $this->latestVarleExport();

        $contextUrl = data_get($export?->context, 'public_url');

        if (is_string($contextUrl) && $contextUrl !== '') {
            return $contextUrl;
        }

        return url('/feeds/varle.xml');
    }

    public function failedCsvUrl(?SyncJob $export = null): ?string
    {
        $export ??= $this->latestVarleExport();

        if ($export === null) {
            return null;
        }

        return route('exports.varle-failed', ['syncJobId' => $export->id]);
    }

    public function exportedVariantsCount(?SyncJob $export): int
    {
        if ($export === null) {
            return 0;
        }

        return (int) ($export->context['exported_variants'] ?? $export->success_items ?? 0);
    }

    public function exportedProductsCount(?SyncJob $export): int
    {
        if ($export === null) {
            return 0;
        }

        return (int) ($export->context['exported_products'] ?? 0);
    }

    public function skippedVariantsCount(?SyncJob $export): int
    {
        if ($export === null) {
            return 0;
        }

        return (int) ($export->context['skipped_variants'] ?? $export->failed_items ?? 0);
    }

    /**
     * @return array{
     *     ready_for_export: int,
     *     pending_review: int,
     *     forced_include: int,
     *     excluded: int,
     *     missing_barcode: int,
     *     missing_category_mapping: int,
     *     missing_variant_images: int,
     *     no_images: int,
     *     out_of_stock_no_backorder: int,
     *     backorder_exportable: int,
     *     vendor_delivery_missing: int,
     *     vendor_disabled: int,
     *     products_with_warnings: int,
     * }
     */
    public function readinessSummary(): array
    {
        return [
            'ready_for_export' => Product::query()->where('varle_is_ready', true)->count(),
            'pending_review' => Product::query()
                ->where('varle_export_status', VarleExportStatus::PendingReview)
                ->count(),
            'forced_include' => Product::query()
                ->where('varle_export_status', VarleExportStatus::Include)
                ->count(),
            'excluded' => Product::query()
                ->where('varle_export_status', VarleExportStatus::Exclude)
                ->count(),
            'missing_barcode' => Product::query()
                ->whereIn('varle_barcode_status', ['some_variants_missing_barcode', 'no_barcodes'])
                ->count(),
            'missing_category_mapping' => Product::query()
                ->where('varle_category_status', 'missing')
                ->count(),
            'missing_variant_images' => Product::query()
                ->whereIn('varle_image_status', ['some_exportable_variants_missing_image', 'no_variant_images'])
                ->count(),
            'no_images' => Product::query()
                ->where('varle_image_status', 'no_images')
                ->count(),
            'out_of_stock_no_backorder' => Product::query()
                ->where('varle_stock_status', 'out_of_stock_blocked')
                ->count(),
            'backorder_exportable' => Product::query()
                ->whereIn('varle_stock_status', ['backorder_only', 'mixed_stock_backorder'])
                ->count(),
            'vendor_delivery_missing' => Product::query()
                ->where('varle_vendor_delivery_rule_status', 'default_rule_used')
                ->count(),
            'vendor_disabled' => Product::query()
                ->whereJsonContains('varle_issue_codes', 'vendor_disabled_for_varle')
                ->count(),
            'products_with_warnings' => Product::query()
                ->where('varle_issue_count', '>', 0)
                ->count(),
        ];
    }

    /**
     * @return Collection<int, object{label: string, count: int}>
     */
    public function breakdownByVendor(int $limit = 15): Collection
    {
        return Product::query()
            ->selectRaw('coalesce(nullif(vendor, \'\'), \'(no vendor)\') as label, count(*) as count')
            ->groupBy('label')
            ->orderByDesc('count')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, object{label: string, count: int}>
     */
    public function breakdownByProductType(int $limit = 15): Collection
    {
        return Product::query()
            ->selectRaw('coalesce(nullif(product_type, \'\'), \'(no type)\') as label, count(*) as count')
            ->groupBy('label')
            ->orderByDesc('count')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, object{label: string, count: int}>
     */
    public function breakdownByBarcodeStatus(): Collection
    {
        return Product::query()
            ->selectRaw('coalesce(varle_barcode_status, \'unknown\') as label, count(*) as count')
            ->groupBy('label')
            ->orderByDesc('count')
            ->get();
    }

    /**
     * @return Collection<int, object{label: string, count: int}>
     */
    public function breakdownByStockStatus(): Collection
    {
        return Product::query()
            ->selectRaw('coalesce(varle_stock_status, \'unknown\') as label, count(*) as count')
            ->groupBy('label')
            ->orderByDesc('count')
            ->get();
    }

    /**
     * @return Collection<int, object{label: string, count: int}>
     */
    public function breakdownByCategoryStatus(): Collection
    {
        return Product::query()
            ->selectRaw('coalesce(varle_category_status, \'unknown\') as label, count(*) as count')
            ->groupBy('label')
            ->orderByDesc('count')
            ->get();
    }

    /**
     * @return array<string, int>
     */
    public function breakdownByIssueCode(): array
    {
        $counts = [];

        foreach ($this->knownIssueCodes() as $issueCode) {
            $counts[$issueCode] = Product::query()
                ->whereJsonContains('varle_issue_codes', $issueCode)
                ->count();
        }

        return $counts;
    }

    /**
     * @return list<string>
     */
    public function knownIssueCodes(): array
    {
        return [
            'pending_review',
            'excluded',
            'unpublished',
            'missing_category_mapping',
            'missing_barcode',
            'missing_variant_image',
            'no_images',
            'price_invalid',
            'out_of_stock_no_backorder',
            'no_stock_anywhere',
            'supplier_stock_stale',
            'no_exportable_variants',
            'missing_delivery_rule',
            'vendor_disabled_for_varle',
            'backorder_disabled_for_vendor',
        ];
    }

    public function productsFilterUrl(array $filters): string
    {
        return ProductResource::getUrl('index', [
            'tableFilters' => $filters,
        ]);
    }

    /**
     * @return array{
     *     pending_review_products: int,
     *     excluded_products: int,
     *     forced_include_products: int,
     *     disabled_category_mappings: int,
     *     new_products_from_latest_import: int,
     * }
     */
    public function exportControlStats(): array
    {
        $latestImport = $this->latestShopifyImport();

        return [
            'pending_review_products' => Product::query()
                ->where('varle_export_status', VarleExportStatus::PendingReview)
                ->count(),
            'excluded_products' => Product::query()
                ->where('varle_export_status', VarleExportStatus::Exclude)
                ->count(),
            'forced_include_products' => Product::query()
                ->where('varle_export_status', VarleExportStatus::Include)
                ->count(),
            'disabled_category_mappings' => CategoryMapping::query()
                ->where('export_enabled', false)
                ->count(),
            'new_products_from_latest_import' => (int) data_get($latestImport?->context, 'new_products_count', 0),
        ];
    }

    /**
     * @return array{missing_category: int, using_fallback: int}
     */
    private function categoryQualityCounts(): array
    {
        $channel = $this->varleChannel();

        if ($channel === null) {
            return [
                'missing_category' => 0,
                'using_fallback' => 0,
            ];
        }

        $missingCategory = 0;
        $usingFallback = 0;

        Product::query()
            ->with('sourceCategories')
            ->orderBy('id')
            ->chunkById(100, function ($products) use ($channel, &$missingCategory, &$usingFallback): void {
                foreach ($products as $product) {
                    $explanation = $this->categoryResolver->explain($product, $channel);

                    if (blank($explanation['resolved_category'])) {
                        $missingCategory++;

                        continue;
                    }

                    if ($explanation['fallback_used']) {
                        $usingFallback++;
                    }
                }
            });

        return [
            'missing_category' => $missingCategory,
            'using_fallback' => $usingFallback,
        ];
    }
}
