<?php

namespace App\Services\Marketplace\Pigu;

/**
 * Compares a generated preview offers feed against the current public feed.
 */
class PiguOfferFeedComparer
{
    private const int MAX_SAMPLES = 12;

    public function __construct(
        private readonly PiguOfferFeedIndex $index = new PiguOfferFeedIndex,
    ) {}

    /**
     * @param  array<string, array{sku: string, ean: string, stock: int, collectionhours: int, price: string}>  $baseline
     * @param  array<string, array{sku: string, ean: string, stock: int, collectionhours: int, price: string}>  $preview
     * @param  array<string, int>  $skippedByCode
     * @return array{
     *     offers_added: int,
     *     offers_removed: int,
     *     stock_changed: int,
     *     collectionhours_changed: int,
     *     price_changed: int,
     *     duplicate_ean_conflicts: int,
     *     duplicate_sku_conflicts: int,
     *     samples: array<string, list<array<string, mixed>>>
     * }
     */
    public function compare(array $baseline, array $preview, array $skippedByCode = []): array
    {
        $added = 0;
        $removed = 0;
        $stockChanged = 0;
        $hoursChanged = 0;
        $priceChanged = 0;

        $samples = [
            'added' => [],
            'removed' => [],
            'stock_changed' => [],
            'collectionhours_changed' => [],
            'price_changed' => [],
        ];

        foreach ($preview as $sku => $offer) {
            if (! isset($baseline[$sku])) {
                $added++;
                $this->pushSample($samples['added'], [
                    'sku' => $sku,
                    'ean' => $offer['ean'],
                    'stock' => $offer['stock'],
                    'collectionhours' => $offer['collectionhours'],
                    'price' => $offer['price'],
                ]);

                continue;
            }

            $before = $baseline[$sku];

            if ((int) $before['stock'] !== (int) $offer['stock']) {
                $stockChanged++;
                $this->pushSample($samples['stock_changed'], [
                    'sku' => $sku,
                    'before' => (int) $before['stock'],
                    'after' => (int) $offer['stock'],
                ]);
            }

            if ((int) $before['collectionhours'] !== (int) $offer['collectionhours']) {
                $hoursChanged++;
                $this->pushSample($samples['collectionhours_changed'], [
                    'sku' => $sku,
                    'before' => (int) $before['collectionhours'],
                    'after' => (int) $offer['collectionhours'],
                ]);
            }

            if ((string) $before['price'] !== (string) $offer['price']) {
                $priceChanged++;
                $this->pushSample($samples['price_changed'], [
                    'sku' => $sku,
                    'before' => (string) $before['price'],
                    'after' => (string) $offer['price'],
                ]);
            }
        }

        foreach ($baseline as $sku => $offer) {
            if (isset($preview[$sku])) {
                continue;
            }

            $removed++;
            $this->pushSample($samples['removed'], [
                'sku' => $sku,
                'ean' => $offer['ean'],
                'stock' => $offer['stock'],
                'collectionhours' => $offer['collectionhours'],
                'price' => $offer['price'],
            ]);
        }

        return [
            'offers_added' => $added,
            'offers_removed' => $removed,
            'stock_changed' => $stockChanged,
            'collectionhours_changed' => $hoursChanged,
            'price_changed' => $priceChanged,
            'duplicate_ean_conflicts' => (int) ($skippedByCode['duplicate_ean'] ?? 0),
            'duplicate_sku_conflicts' => (int) ($skippedByCode['duplicate_sku'] ?? 0),
            'samples' => $samples,
        ];
    }

    /**
     * @return array{
     *     offers_added: int,
     *     offers_removed: int,
     *     stock_changed: int,
     *     collectionhours_changed: int,
     *     price_changed: int,
     *     duplicate_ean_conflicts: int,
     *     duplicate_sku_conflicts: int,
     *     samples: array<string, list<array<string, mixed>>>,
     *     baseline_offers: int,
     *     preview_offers: int
     * }
     */
    public function compareFiles(?string $baselineAbsolute, string $previewAbsolute, array $skippedByCode = []): array
    {
        $baseline = $this->index->loadFromFile($baselineAbsolute);
        $preview = $this->index->loadFromFile($previewAbsolute);
        $result = $this->compare($baseline, $preview, $skippedByCode);
        $result['baseline_offers'] = count($baseline);
        $result['preview_offers'] = count($preview);

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $bucket
     * @param  array<string, mixed>  $row
     */
    private function pushSample(array &$bucket, array $row): void
    {
        if (count($bucket) >= self::MAX_SAMPLES) {
            return;
        }

        $bucket[] = $row;
    }
}
