<?php

namespace App\Services\Marketplace;

use App\Enums\VarleExportStatus;
use App\Models\Product;
use App\Models\SourceCategory;
use App\Services\Marketplace\Varle\VarleReadinessRefreshService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class CategoryBulkApprovalService
{
    public function __construct(
        private readonly VarleReadinessRefreshService $readinessRefreshService,
    ) {}

    /**
     * @param  array<int, int>  $categoryIds
     * @return array<int, int>
     */
    public function distinctProductIdsForCategories(array $categoryIds): array
    {
        $categoryIds = $this->normalizeIds($categoryIds);

        if ($categoryIds === []) {
            return [];
        }

        return Product::query()
            ->whereHas('sourceCategories', fn (Builder $query): Builder => $query->whereIn('source_categories.id', $categoryIds))
            ->distinct()
            ->pluck('products.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array<int, int>  $categoryIds
     * @return array{
     *     category_count: int,
     *     affected_product_count: int,
     *     status_breakdown: array<string, int>
     * }
     */
    public function preview(array $categoryIds): array
    {
        $categoryIds = $this->normalizeIds($categoryIds);
        $productIds = $this->distinctProductIdsForCategories($categoryIds);

        return [
            'category_count' => count($categoryIds),
            'affected_product_count' => count($productIds),
            'status_breakdown' => $this->statusBreakdownForProductIds($productIds),
        ];
    }

    /**
     * @param  array<int, int>  $categoryIds
     */
    public function apply(
        array $categoryIds,
        VarleExportStatus $status,
        bool $dispatchReadinessRefresh = true,
    ): CategoryBulkApprovalResult {
        $categoryIds = $this->normalizeIds($categoryIds);
        $productIds = $this->distinctProductIdsForCategories($categoryIds);

        if ($productIds === []) {
            return new CategoryBulkApprovalResult(0, []);
        }

        $updated = Product::query()
            ->whereIn('id', $productIds)
            ->update(['varle_export_status' => $status->value]);

        $readinessSyncJobId = null;
        $readinessQueued = false;

        if ($dispatchReadinessRefresh && $updated > 0) {
            $dispatch = $this->readinessRefreshService->dispatch($productIds);

            if ($dispatch->dispatched) {
                $readinessQueued = true;
                $readinessSyncJobId = $dispatch->syncJob?->id;
            }
        }

        return new CategoryBulkApprovalResult(
            updatedCount: (int) $updated,
            productIds: $productIds,
            readinessSyncJobId: $readinessSyncJobId,
            readinessQueued: $readinessQueued,
        );
    }

    /**
     * @param  array<int, int>  $productIds
     * @return array<string, int>
     */
    public function statusBreakdownForProductIds(array $productIds): array
    {
        if ($productIds === []) {
            return $this->emptyStatusBreakdown();
        }

        $rows = Product::query()
            ->select('varle_export_status', DB::raw('COUNT(*) as aggregate'))
            ->whereIn('id', $productIds)
            ->groupBy('varle_export_status')
            ->pluck('aggregate', 'varle_export_status');

        $breakdown = $this->emptyStatusBreakdown();

        foreach ($rows as $status => $count) {
            $key = $status instanceof VarleExportStatus
                ? $status->value
                : (string) ($status ?? 'unknown');

            if (! isset($breakdown[$key])) {
                $breakdown['unknown'] = ($breakdown['unknown'] ?? 0) + (int) $count;

                continue;
            }

            $breakdown[$key] = (int) $count;
        }

        return $breakdown;
    }

    public function previewDescription(array $categoryIds, VarleExportStatus $targetStatus): string
    {
        $preview = $this->preview($categoryIds);

        $lines = [
            'Selected categories: '.$preview['category_count'],
            'Affected unique products: '.$preview['affected_product_count'],
            '',
            'Current statuses:',
            '- Include: '.$preview['status_breakdown']['include'],
            '- Exclude: '.$preview['status_breakdown']['exclude'],
            '- Pending review: '.$preview['status_breakdown']['pending_review'],
            '- Auto: '.$preview['status_breakdown']['auto'],
            '',
            'After action:',
            '- Target status: '.$targetStatus->label(),
        ];

        return implode("\n", $lines);
    }

    /**
     * @return array<string, int>
     */
    private function emptyStatusBreakdown(): array
    {
        return [
            'include' => 0,
            'exclude' => 0,
            'pending_review' => 0,
            'auto' => 0,
            'unknown' => 0,
        ];
    }

    /**
     * @param  array<int, int>  $categoryIds
     * @return array<int, int>
     */
    private function normalizeIds(array $categoryIds): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (mixed $id): int => (int) $id,
            $categoryIds,
        ))));
    }
}
