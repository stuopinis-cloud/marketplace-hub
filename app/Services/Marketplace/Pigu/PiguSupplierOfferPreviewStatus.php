<?php

namespace App\Services\Marketplace\Pigu;

use Illuminate\Support\Facades\Storage;

/**
 * Persists preview generation state under the private preview directory.
 */
class PiguSupplierOfferPreviewStatus
{
    public const string STATUS_IDLE = 'idle';

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_RUNNING = 'running';

    public const string STATUS_READY = 'ready';

    public const string STATUS_FAILED = 'failed';

    public static function path(int $supplierId): string
    {
        return PiguOfferFeedExporter::previewDirectory($supplierId).'/status.json';
    }

    /**
     * @return array<string, mixed>
     */
    public function read(int $supplierId): array
    {
        $disk = Storage::disk(PiguOfferFeedExporter::PREVIEW_DISK);
        $path = self::path($supplierId);

        if (! $disk->exists($path)) {
            return $this->defaults($supplierId);
        }

        $decoded = json_decode((string) $disk->get($path), true);

        if (! is_array($decoded)) {
            return $this->defaults($supplierId);
        }

        return array_merge($this->defaults($supplierId), $decoded);
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    public function update(int $supplierId, array $patch): array
    {
        $current = $this->read($supplierId);
        $merged = array_merge($current, $patch, [
            'supplier_id' => $supplierId,
            'updated_at' => now()->toIso8601String(),
        ]);

        $disk = Storage::disk(PiguOfferFeedExporter::PREVIEW_DISK);
        $disk->makeDirectory(PiguOfferFeedExporter::previewDirectory($supplierId));
        $disk->put(self::path($supplierId), json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $merged;
    }

    public function isBusy(int $supplierId): bool
    {
        return in_array($this->read($supplierId)['status'] ?? null, [
            self::STATUS_PENDING,
            self::STATUS_RUNNING,
        ], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(int $supplierId): array
    {
        return [
            'supplier_id' => $supplierId,
            'status' => self::STATUS_IDLE,
            'queued_at' => null,
            'started_at' => null,
            'finished_at' => null,
            'duration_seconds' => null,
            'products_processed' => 0,
            'offers_generated' => 0,
            'rejected' => 0,
            'error' => null,
            'stats' => null,
            'updated_at' => null,
        ];
    }
}
