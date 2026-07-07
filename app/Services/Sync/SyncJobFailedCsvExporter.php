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
            'sku',
            'product_id',
            'product_title',
            'product_handle',
            'variant_id',
            'variant_title',
            'variant_sku',
            'variant_barcode',
            'variant_image_url',
            'product_images_count',
            'selected_export_images_count',
            'varle_export_status',
            'category_mapping_export_enabled',
            'product_is_published',
            'product_published_at',
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
                ->with(['product', 'variant.product'])
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
        $product = $item->product;

        if ($product === null && $variant !== null) {
            $product = $variant->product;
        }

        return [
            $syncJob->id,
            $item->id,
            $item->status?->value ?? (string) $item->status,
            (string) ($item->message ?? ''),
            (string) ($item->sku ?? ''),
            $item->product_id,
            (string) ($product?->title ?? ''),
            (string) ($product?->handle ?? ''),
            $item->variant_id,
            (string) ($variant?->title ?? ''),
            (string) ($variant?->sku ?? ''),
            (string) ($variant?->barcode ?? ''),
            $this->payloadValue($item, 'variant_image_url'),
            $this->payloadValue($item, 'product_images_count'),
            $this->payloadValue($item, 'selected_export_images_count'),
            $this->payloadValue($item, 'varle_export_status'),
            $this->payloadValue($item, 'category_mapping_export_enabled'),
            $this->payloadValue($item, 'product_is_published'),
            $this->payloadValue($item, 'product_published_at'),
            $item->created_at?->toDateTimeString(),
        ];
    }

    /**
     * @return array<int, array<int, string|int|null>>
     */
    private function warningRows(SyncJob $syncJob): array
    {
        $warnings = collect($syncJob->context['warnings'] ?? [])
            ->filter(fn ($warning) => is_string($warning) && $warning !== '')
            ->values();

        return $warnings->map(function (string $warning) use ($syncJob): array {
            $handle = null;

            if (preg_match('/^Product ([^:]+): /', $warning, $matches) === 1) {
                $handle = trim($matches[1]);
            }

            return [
                $syncJob->id,
                null,
                'warning',
                $warning,
                '',
                null,
                '',
                $handle,
                null,
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                $syncJob->finished_at?->toDateTimeString() ?? $syncJob->created_at?->toDateTimeString(),
            ];
        })->all();
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
