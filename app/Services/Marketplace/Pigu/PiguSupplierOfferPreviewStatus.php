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

    public static function path(
        int $supplierId,
        string $scope = PiguExportOptions::PREVIEW_SCOPE_CONTRIBUTION,
    ): string {
        return PiguOfferFeedExporter::previewDirectory($supplierId, $scope).'/status.json';
    }

    /**
     * @return array<string, mixed>
     */
    public function read(
        int $supplierId,
        string $scope = PiguExportOptions::PREVIEW_SCOPE_CONTRIBUTION,
    ): array {
        $disk = Storage::disk(PiguOfferFeedExporter::PREVIEW_DISK);
        $path = self::path($supplierId, $scope);

        if (! $disk->exists($path)) {
            return $this->defaults($supplierId, $scope);
        }

        $decoded = json_decode((string) $disk->get($path), true);

        if (! is_array($decoded)) {
            return $this->defaults($supplierId, $scope);
        }

        return array_merge($this->defaults($supplierId, $scope), $decoded);
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    public function update(
        int $supplierId,
        array $patch,
        string $scope = PiguExportOptions::PREVIEW_SCOPE_CONTRIBUTION,
    ): array {
        $current = $this->read($supplierId, $scope);
        $merged = array_merge($current, $patch, [
            'supplier_id' => $supplierId,
            'preview_scope' => $scope,
            'updated_at' => now()->toIso8601String(),
        ]);

        $disk = Storage::disk(PiguOfferFeedExporter::PREVIEW_DISK);
        $disk->makeDirectory(PiguOfferFeedExporter::previewDirectory($supplierId, $scope));
        $disk->put(self::path($supplierId, $scope), json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $merged;
    }

    public function isBusy(
        int $supplierId,
        string $scope = PiguExportOptions::PREVIEW_SCOPE_CONTRIBUTION,
    ): bool {
        return in_array($this->read($supplierId, $scope)['status'] ?? null, [
            self::STATUS_PENDING,
            self::STATUS_RUNNING,
        ], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(int $supplierId, string $scope): array
    {
        return [
            'supplier_id' => $supplierId,
            'preview_scope' => $scope,
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
