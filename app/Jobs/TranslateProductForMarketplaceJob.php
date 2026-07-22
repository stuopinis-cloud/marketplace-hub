<?php

namespace App\Jobs;

use App\Enums\MarketplaceTranslationStatus;
use App\Models\MarketplaceTranslation;
use App\Models\Product;
use App\Services\Marketplace\Translations\MarketplaceTranslationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class TranslateProductForMarketplaceJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public readonly int $productId,
        public readonly string $marketplace = 'ebay',
        public readonly string $locale = 'en',
        public readonly bool $dispatchFieldJobs = true,
    ) {}

    public function handle(MarketplaceTranslationService $translations): void
    {
        $lock = Cache::lock(
            sprintf('translate-product:%d:%s:%s', $this->productId, $this->marketplace, $this->locale),
            600,
        );

        if (! $lock->get()) {
            return;
        }

        try {
            $product = Product::query()->with('variants')->find($this->productId);

            if ($product === null) {
                return;
            }

            $records = $translations->queueProduct($product, $this->locale, $this->marketplace);

            if (! $this->dispatchFieldJobs) {
                return;
            }

            foreach ($records as $record) {
                if (! $record instanceof MarketplaceTranslation) {
                    continue;
                }

                if (in_array($record->status, [
                    MarketplaceTranslationStatus::Missing,
                    MarketplaceTranslationStatus::Queued,
                    MarketplaceTranslationStatus::Failed,
                ], true)) {
                    TranslateProductFieldJob::dispatch($record->id);
                }
            }
        } finally {
            $lock->release();
        }
    }
}
