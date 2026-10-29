<?php

namespace App\Jobs;

use App\Models\Supplier;
use App\Services\Marketplace\Pigu\PiguExportOptions;
use App\Services\Marketplace\Pigu\PiguSupplierOfferPreviewService;
use App\Services\Marketplace\Pigu\PiguSupplierOfferPreviewStatus;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class GeneratePiguSupplierOfferPreviewJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $maxExceptions = 1;

    public function __construct(
        public readonly int $supplierId,
        public readonly string $previewScope = PiguExportOptions::PREVIEW_SCOPE_CONTRIBUTION,
    ) {
        $this->onQueue((string) config('marketplace.exports.pigu.preview.queue', 'default'));
        $this->timeout = (int) config('marketplace.exports.pigu.preview.timeout', 1800);
        $this->tries = max(1, (int) config('marketplace.exports.pigu.preview.tries', 1));
    }

    public function uniqueId(): string
    {
        return 'pigu-supplier-offer-preview:'.$this->previewScope.':'.$this->supplierId;
    }

    public function uniqueFor(): int
    {
        return (int) config('marketplace.exports.pigu.preview.lock_seconds', 1900);
    }

    public function handle(
        PiguSupplierOfferPreviewService $preview,
        PiguSupplierOfferPreviewStatus $status,
    ): void {
        $lock = Cache::lock($this->lockKey(), $this->uniqueFor());

        if (! $lock->get()) {
            return;
        }

        try {
            $supplier = Supplier::query()->find($this->supplierId);
            if (! $supplier instanceof Supplier) {
                $status->update($this->supplierId, [
                    'status' => PiguSupplierOfferPreviewStatus::STATUS_FAILED,
                    'error' => 'Tiekėjas nerastas.',
                    'finished_at' => now()->toIso8601String(),
                ], $this->previewScope);

                return;
            }

            $preview->runQueuedGeneration($supplier, $this->previewScope);
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(PiguSupplierOfferPreviewStatus::class)->update($this->supplierId, [
            'status' => PiguSupplierOfferPreviewStatus::STATUS_FAILED,
            'error' => $exception?->getMessage() ?: 'Nežinoma generavimo klaida.',
            'finished_at' => now()->toIso8601String(),
        ], $this->previewScope);

        try {
            Cache::lock($this->lockKey(), $this->uniqueFor())->forceRelease();
        } catch (Throwable) {
            // Best-effort cleanup.
        }
    }

    private function lockKey(): string
    {
        return 'lock:'.$this->uniqueId();
    }
}
