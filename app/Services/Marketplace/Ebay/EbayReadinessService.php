<?php

namespace App\Services\Marketplace\Ebay;

use App\Enums\MarketplaceTranslationStatus;
use App\Models\MarketplaceTranslation;
use App\Models\Product;
use App\Services\Marketplace\Translations\MarketplaceTranslationService;

class EbayReadinessService
{
    public function __construct(
        private readonly MarketplaceTranslationService $translations = new MarketplaceTranslationService,
    ) {}

    /**
     * @return array{
     *     is_ready: bool,
     *     issue_codes: list<string>,
     *     warnings: list<string>
     * }
     */
    public function analyze(Product $product, string $locale = 'en', string $marketplace = 'ebay'): array
    {
        $product->loadMissing(['variants', 'images']);

        $issues = [];
        $warnings = [];

        $titleTranslation = $this->translations->getTranslation(
            $product,
            MarketplaceTranslation::FIELD_TITLE,
            $locale,
            (string) $product->title,
            $marketplace,
        );

        if ($titleTranslation === null || ! $titleTranslation->isUsable()) {
            $issues[] = 'missing_english_title';
            $warnings[] = 'Missing English title translation.';
        }

        $descriptionSource = (string) ($product->description_html ?? '');

        if (trim(strip_tags($descriptionSource)) !== '') {
            $descriptionTranslation = $this->translations->getTranslation(
                $product,
                MarketplaceTranslation::FIELD_DESCRIPTION,
                $locale,
                $descriptionSource,
                $marketplace,
            );

            if ($descriptionTranslation === null || ! $descriptionTranslation->isUsable()) {
                $issues[] = 'missing_english_description';
                $warnings[] = 'Missing English description translation.';
            }
        }

        foreach ($product->variants as $variant) {
            for ($index = 1; $index <= 3; $index++) {
                $value = trim((string) ($variant->{"option{$index}_value"} ?? $variant->{"option{$index}"} ?? ''));

                if ($value === '') {
                    continue;
                }

                $optionTranslation = $this->translations->getTranslation(
                    $variant,
                    MarketplaceTranslation::FIELD_OPTION_VALUE.':'.$index,
                    $locale,
                    $value,
                    $marketplace,
                );

                if ($optionTranslation === null || ! $optionTranslation->isUsable()) {
                    $issues[] = 'missing_translated_option_values';
                    $warnings[] = 'Missing translated option values.';
                    break 2;
                }
            }

            if (blank($variant->barcode)) {
                $warnings[] = 'Variant missing barcode/identifier.';
                $issues[] = 'missing_barcode';
            }
        }

        if ($product->images->isEmpty()) {
            $issues[] = 'missing_images';
            $warnings[] = 'Product has no images.';
        }

        $maxTitle = (int) config('marketplace.exports.ebay.title_max_length', 80);
        $englishTitle = $titleTranslation?->translated_text ?: $product->title;

        if (is_string($englishTitle) && mb_strlen($englishTitle) > $maxTitle) {
            $issues[] = 'title_too_long';
            $warnings[] = 'English title exceeds configured eBay max length.';
        }

        return [
            'is_ready' => $issues === [],
            'issue_codes' => array_values(array_unique($issues)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @return array{missing_title: int, missing_description: int, failed: int, approved: int}
     */
    public function translationStatusSummary(string $marketplace = 'ebay', string $locale = 'en'): array
    {
        $base = MarketplaceTranslation::query()
            ->where('marketplace', $marketplace)
            ->where('locale', $locale);

        return [
            'missing_title' => (clone $base)->where('field', MarketplaceTranslation::FIELD_TITLE)
                ->where('status', MarketplaceTranslationStatus::Missing)->count(),
            'missing_description' => (clone $base)->where('field', MarketplaceTranslation::FIELD_DESCRIPTION)
                ->where('status', MarketplaceTranslationStatus::Missing)->count(),
            'failed' => (clone $base)->where('status', MarketplaceTranslationStatus::Failed)->count(),
            'approved' => (clone $base)->where('status', MarketplaceTranslationStatus::Approved)->count(),
        ];
    }
}
