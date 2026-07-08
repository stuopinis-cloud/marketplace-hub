<?php

namespace App\Services\Sync;

use App\Enums\SyncJobItemStatus;
use App\Models\SyncJob;
use App\Models\SyncJobItem;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SyncJobFailedCsvExporter
{
    public const string RELATIVE_DIRECTORY = 'exports';

    /**
     * @return array<int, string>
     */
    public function headers(): array
    {
        return [
            'sync_job_id',
            'sync_job_item_id',
            'status',
            'message',
            'product_id',
            'product_handle',
            'product_title',
            'vendor',
            'product_type',
            'source_categories',
            'varle_export_status',
            'mapped_category',
            'category_status',
            'variant_id',
            'variant_sku',
            'variant_title',
            'barcode',
            'has_barcode',
            'price',
            'compare_at_price',
            'quantity',
            'inventory_policy',
            'backorder_allowed',
            'has_variant_image',
            'variant_image_url',
            'product_images_count',
            'selected_export_images_count',
            'variant_images_count',
            'generic_gallery_images_count',
            'forbidden_variant_images_count',
            'stock_status',
            'delivery_text',
            'vendor_delivery_rule',
            'issue_code',
            'issue_message',
            'can_fix_in_shopify',
            'can_fix_in_hub',
            'created_at',
        ];
    }

    public function export(SyncJob $syncJob): string
    {
        $relativePath = $this->relativePathFor($syncJob);

        Storage::disk('public')->makeDirectory(self::RELATIVE_DIRECTORY);

        $absolutePath = Storage::disk('public')->path($relativePath);
        $handle = fopen($absolutePath, 'w');

        if ($handle === false) {
            throw new \RuntimeException('Unable to open CSV file for writing.');
        }

        try {
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $this->headers());

            $items = $syncJob->items()
                ->with(['product.sourceCategories', 'variant.product'])
                ->where('status', SyncJobItemStatus::Failed)
                ->orderBy('id')
                ->get();

            foreach ($items as $item) {
                fputcsv($handle, $this->rowForItem($syncJob, $item));
            }

            foreach ($this->warningRows($syncJob) as $row) {
                fputcsv($handle, $row);
            }
        } finally {
            fclose($handle);
        }

        return $relativePath;
    }

    public function downloadResponse(SyncJob $syncJob): BinaryFileResponse
    {
        $relativePath = $this->export($syncJob);

        return response()->download(
            Storage::disk('public')->path($relativePath),
            basename($relativePath),
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    public function relativePathFor(SyncJob $syncJob): string
    {
        return self::RELATIVE_DIRECTORY.'/varle_failed_'.$syncJob->id.'.csv';
    }

    public function publicUrl(string $relativePath): string
    {
        return Storage::disk('public')->url($relativePath);
    }

    public function resolveSyncJob(?int $syncJobId): ?SyncJob
    {
        if ($syncJobId !== null) {
            return SyncJob::query()->find($syncJobId);
        }

        return SyncJob::query()
            ->where('type', 'export')
            ->where('channel', 'varle')
            ->latest('id')
            ->first();
    }

    /**
     * @return array<int, string|int|null>
     */
    private function rowForItem(SyncJob $syncJob, SyncJobItem $item): array
    {
        $variant = $item->variant;
        $product = $item->product ?? $variant?->product;

        return [
            $syncJob->id,
            $item->id,
            $item->status?->value ?? (string) $item->status,
            (string) ($item->message ?? ''),
            $item->product_id,
            (string) ($product?->handle ?? ''),
            (string) ($product?->title ?? ''),
            $this->payloadValue($item, 'vendor') ?: (string) ($product?->vendor ?? ''),
            $this->payloadValue($item, 'product_type') ?: (string) ($product?->product_type ?? ''),
            $this->payloadValue($item, 'source_categories'),
            $this->payloadValue($item, 'varle_export_status'),
            $this->payloadValue($item, 'mapped_category'),
            $this->payloadValue($item, 'category_status'),
            $item->variant_id,
            $this->payloadValue($item, 'variant_sku') ?: (string) ($variant?->sku ?? ''),
            $this->payloadValue($item, 'variant_title') ?: (string) ($variant?->title ?? ''),
            $this->payloadValue($item, 'barcode') ?: (string) ($variant?->barcode ?? ''),
            $this->payloadValue($item, 'has_barcode'),
            $this->payloadValue($item, 'price') ?: (string) ($variant?->price ?? ''),
            $this->payloadValue($item, 'compare_at_price') ?: (string) ($variant?->compare_at_price ?? ''),
            $this->payloadValue($item, 'quantity'),
            $this->payloadValue($item, 'inventory_policy') ?: (string) ($variant?->inventory_policy ?? ''),
            $this->payloadValue($item, 'backorder_allowed'),
            $this->payloadValue($item, 'has_variant_image'),
            $this->payloadValue($item, 'variant_image_url') ?: (string) ($variant?->image_url ?? ''),
            $this->payloadValue($item, 'product_images_count'),
            $this->payloadValue($item, 'selected_export_images_count'),
            $this->payloadValue($item, 'variant_images_count'),
            $this->payloadValue($item, 'generic_gallery_images_count'),
            $this->payloadValue($item, 'forbidden_variant_images_count'),
            $this->payloadValue($item, 'stock_status'),
            $this->payloadValue($item, 'delivery_text'),
            $this->payloadValue($item, 'vendor_delivery_rule'),
            $this->payloadValue($item, 'issue_code'),
            $this->payloadValue($item, 'issue_message') ?: (string) ($item->message ?? ''),
            $this->payloadValue($item, 'can_fix_in_shopify'),
            $this->payloadValue($item, 'can_fix_in_hub'),
            $item->created_at?->toDateTimeString(),
        ];
    }

    /**
     * @return array<int, array<int, string|int|null>>
     */
    private function warningRows(SyncJob $syncJob): array
    {
        $empty = array_fill(0, count($this->headers()) - 1, '');

        return collect($syncJob->context['warnings'] ?? [])
            ->filter(fn ($warning) => is_string($warning) && $warning !== '')
            ->map(function (string $warning) use ($syncJob, $empty): array {
                $row = array_merge([$syncJob->id, null, 'warning', $warning], array_slice($empty, 3));
                $row[5] = preg_match('/^Product ([^:]+): /', $warning, $matches) === 1 ? trim($matches[1]) : '';
                $row[count($this->headers()) - 1] = $syncJob->finished_at?->toDateTimeString() ?? $syncJob->created_at?->toDateTimeString();

                return $row;
            })
            ->all();
    }

    private function payloadValue(SyncJobItem $item, string $key): string
    {
        $value = data_get($item->payload, $key);

        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}
