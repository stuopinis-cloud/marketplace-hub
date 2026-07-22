<?php

namespace App\Services\Marketplace\Translations;

use App\Enums\MarketplaceTranslationStatus;
use App\Jobs\TranslateProductFieldJob;
use App\Jobs\TranslateProductForMarketplaceJob;
use App\Models\MarketplaceTranslation;
use App\Models\Product;
use Illuminate\Support\Collection;

class TranslationQueueService
{
    public function __construct(
        private readonly MarketplaceTranslationService $translations = new MarketplaceTranslationService,
    ) {}

    /**
     * @return array{products_queued: int, fields_queued: int}
     */
    public function queueMissingForMarketplace(string $marketplace = 'ebay', string $locale = 'en', ?int $limit = null): array
    {
        $productsQueued = 0;
        $fieldsQueued = 0;

        $query = Product::query()->orderBy('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $query->chunkById(100, function (Collection $products) use ($marketplace, $locale, &$productsQueued, &$fieldsQueued): void {
            foreach ($products as $product) {
                TranslateProductForMarketplaceJob::dispatch($product->id, $marketplace, $locale);
                $productsQueued++;
            }
        });

        // Also re-queue existing missing/failed rows not yet tied to a product job cycle.
        $fieldQuery = MarketplaceTranslation::query()
            ->where('marketplace', $marketplace)
            ->where('locale', $locale)
            ->whereIn('status', [
                MarketplaceTranslationStatus::Missing->value,
                MarketplaceTranslationStatus::Failed->value,
                MarketplaceTranslationStatus::Queued->value,
            ]);

        $fieldQuery->orderBy('id')->chunkById(200, function (Collection $rows) use (&$fieldsQueued): void {
            foreach ($rows as $row) {
                TranslateProductFieldJob::dispatch($row->id);
                $fieldsQueued++;
            }
        });

        return [
            'products_queued' => $productsQueued,
            'fields_queued' => $fieldsQueued,
        ];
    }

    public function queueProduct(int $productId, string $marketplace = 'ebay', string $locale = 'en'): void
    {
        TranslateProductForMarketplaceJob::dispatch($productId, $marketplace, $locale);
    }

    /**
     * @param  list<int>  $translationIds
     */
    public function queueTranslationIds(array $translationIds): int
    {
        $count = 0;

        foreach ($translationIds as $id) {
            MarketplaceTranslation::query()->whereKey($id)->update([
                'status' => MarketplaceTranslationStatus::Queued,
                'error_message' => null,
            ]);
            TranslateProductFieldJob::dispatch((int) $id);
            $count++;
        }

        return $count;
    }
}
