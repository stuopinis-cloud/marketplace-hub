<?php

namespace App\Services\Marketplace\Translations;

use App\Enums\MarketplaceTranslationStatus;
use App\Jobs\TranslateProductFieldJob;
use App\Jobs\TranslateProductForMarketplaceJob;
use App\Models\MarketplaceTranslation;
use App\Models\Product;

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

        $productQuery = Product::query()->orderBy('id');

        if ($limit !== null) {
            $productQuery->limit(max(1, $limit));
        }

        $productIds = $productQuery->pluck('id');

        foreach ($productIds as $productId) {
            TranslateProductForMarketplaceJob::dispatch((int) $productId, $marketplace, $locale);
            $productsQueued++;
        }

        $fieldQuery = MarketplaceTranslation::query()
            ->where('marketplace', $marketplace)
            ->where('locale', $locale)
            ->whereIn('status', [
                MarketplaceTranslationStatus::Missing->value,
                MarketplaceTranslationStatus::Failed->value,
            ])
            ->orderBy('id');

        if ($limit !== null) {
            $fieldQuery->limit(max(1, $limit));
        }

        foreach ($fieldQuery->get() as $row) {
            if ($row->status === MarketplaceTranslationStatus::Queued) {
                continue;
            }

            $row->update([
                'status' => MarketplaceTranslationStatus::Queued,
                'error_message' => null,
            ]);
            TranslateProductFieldJob::dispatch($row->id);
            $fieldsQueued++;
        }

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
            $translation = MarketplaceTranslation::query()->find($id);

            if ($translation === null) {
                continue;
            }

            if ($translation->status === MarketplaceTranslationStatus::Queued) {
                continue;
            }

            $translation->update([
                'status' => MarketplaceTranslationStatus::Queued,
                'error_message' => null,
            ]);
            TranslateProductFieldJob::dispatch((int) $id);
            $count++;
        }

        return $count;
    }
}
