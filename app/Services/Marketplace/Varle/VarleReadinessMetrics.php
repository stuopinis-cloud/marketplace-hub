<?php

namespace App\Services\Marketplace\Varle;

use App\Enums\ProductStatus;
use App\Enums\VarleExportStatus;
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
