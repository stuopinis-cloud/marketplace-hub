<?php

namespace App\Services\Marketplace\Translations;

use App\Contracts\Marketplace\MarketplaceTranslatorInterface;
use App\Enums\MarketplaceTranslationStatus;
use App\Models\MarketplaceTranslation;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class MarketplaceTranslationService
{
    public function __construct(
        private readonly TranslationGlossary $glossary = new TranslationGlossary,
    ) {}

    public function getTranslation(
        ?Model $entity,
        string $field,
        string $locale,
        string $sourceText,
        ?string $marketplace = null,
    ): ?MarketplaceTranslation {
        $sourceText = $this->normalizeSource($sourceText);

        if ($sourceText === '') {
            return null;
        }

        $hash = MarketplaceTranslation::hashSource($sourceText);

        $query = MarketplaceTranslation::query()
            ->where('locale', $locale)
            ->where('field', $field)
            ->where('source_text_hash', $hash)
            ->where('marketplace', $marketplace);

        if ($entity !== null) {
            $query->whereMorphedTo('translatable', $entity);
        } else {
            $query->whereNull('translatable_type')->whereNull('translatable_id');
        }

        return $query->first();
    }

    public function getOrCreateMissing(
        ?Model $entity,
        string $field,
        string $locale,
        string $sourceText,
        ?string $marketplace = null,
    ): ?MarketplaceTranslation {
        $sourceText = $this->normalizeSource($sourceText);

        if ($sourceText === '') {
            return null;
        }

        if ($this->shouldSkipTranslation($sourceText, $field)) {
            return null;
        }

        $existing = $this->getTranslation($entity, $field, $locale, $sourceText, $marketplace);

        if ($existing !== null) {
            return $existing;
        }

        $glossaryHit = $this->glossary->lookup($sourceText);

        return MarketplaceTranslation::query()->create([
            'translatable_type' => $entity?->getMorphClass(),
            'translatable_id' => $entity?->getKey(),
            'marketplace' => $marketplace,
            'locale' => $locale,
            'field' => $field,
            'source_text_hash' => MarketplaceTranslation::hashSource($sourceText),
            'source_text' => $sourceText,
            'translated_text' => $glossaryHit,
            'status' => $glossaryHit !== null
                ? MarketplaceTranslationStatus::AutoTranslated
                : MarketplaceTranslationStatus::Missing,
            'provider' => $glossaryHit !== null ? 'glossary' : null,
            'translated_at' => $glossaryHit !== null ? now() : null,
        ]);
    }

    public function applyTranslationOrFallback(
        ?Model $entity,
        string $field,
        string $locale,
        string $sourceText,
        ?string $marketplace = null,
    ): string {
        $sourceText = $this->normalizeSource($sourceText);

        if ($sourceText === '') {
            return '';
        }

        if ($this->shouldSkipTranslation($sourceText, $field)) {
            return $sourceText;
        }

        $glossaryHit = $this->glossary->lookup($sourceText);

        if ($glossaryHit !== null) {
            $this->getOrCreateMissing($entity, $field, $locale, $sourceText, $marketplace);

            return $glossaryHit;
        }

        $translation = $this->getOrCreateMissing($entity, $field, $locale, $sourceText, $marketplace);

        if ($translation === null) {
            return $sourceText;
        }

        foreach ([
            MarketplaceTranslationStatus::Approved,
            MarketplaceTranslationStatus::Reviewed,
            MarketplaceTranslationStatus::AutoTranslated,
        ] as $status) {
            if ($translation->status === $status && filled($translation->translated_text)) {
                return (string) $translation->translated_text;
            }
        }

        return $sourceText;
    }

    /**
     * @return list<MarketplaceTranslation>
     */
    public function queueMissing(
        ?Model $entity,
        string $field,
        string $locale,
        string $sourceText,
        ?string $marketplace = null,
    ): array {
        $translation = $this->getOrCreateMissing($entity, $field, $locale, $sourceText, $marketplace);

        if ($translation === null) {
            return [];
        }

        if ($translation->isUsable()) {
            return [$translation];
        }

        if ($translation->status !== MarketplaceTranslationStatus::Queued) {
            $translation->update([
                'status' => MarketplaceTranslationStatus::Queued,
                'error_message' => null,
            ]);
        }

        return [$translation->fresh()];
    }

    /**
     * Queue all translatable fields for a product (and its variants).
     *
     * @return list<MarketplaceTranslation>
     */
    public function queueProduct(Product $product, string $locale, ?string $marketplace = null): array
    {
        $queued = [];

        $product->loadMissing('variants');

        foreach ($this->productFieldSources($product) as $field => $source) {
            $queued = [...$queued, ...$this->queueMissing($product, $field, $locale, $source, $marketplace)];
        }

        foreach ($product->variants as $variant) {
            foreach ($this->variantFieldSources($variant) as $field => $source) {
                $queued = [...$queued, ...$this->queueMissing($variant, $field, $locale, $source, $marketplace)];
            }
        }

        return $queued;
    }

    /**
     * @return array<string, string>
     */
    public function productFieldSources(Product $product): array
    {
        $fields = [
            MarketplaceTranslation::FIELD_TITLE => (string) ($product->title ?? ''),
            MarketplaceTranslation::FIELD_DESCRIPTION => (string) ($product->description_html ?? ''),
        ];

        return array_filter($fields, fn (string $value): bool => trim($value) !== '');
    }

    /**
     * @return array<string, string>
     */
    public function variantFieldSources(ProductVariant $variant): array
    {
        $fields = [];

        for ($index = 1; $index <= 3; $index++) {
            $name = trim((string) ($variant->{"option{$index}_name"} ?? ''));
            $value = trim((string) ($variant->{"option{$index}_value"} ?? $variant->{"option{$index}"} ?? ''));

            if ($name !== '') {
                $fields[MarketplaceTranslation::FIELD_OPTION_NAME.':'.$index] = $name;
            }

            if ($value !== '') {
                $fields[MarketplaceTranslation::FIELD_OPTION_VALUE.':'.$index] = $value;
            }
        }

        return $fields;
    }

    public function canonicalField(string $field): string
    {
        if (str_contains($field, ':')) {
            return Str::before($field, ':');
        }

        return $field;
    }

    public function translateRecord(
        MarketplaceTranslation $translation,
        MarketplaceTranslatorInterface $translator,
        string $sourceLocale = 'lt',
    ): MarketplaceTranslation {
        $field = $this->canonicalField($translation->field);

        if ($this->shouldSkipTranslation($translation->source_text, $field)) {
            $translation->update([
                'translated_text' => $translation->source_text,
                'status' => MarketplaceTranslationStatus::AutoTranslated,
                'provider' => 'protected',
                'translated_at' => now(),
                'error_message' => null,
            ]);

            return $translation->fresh();
        }

        $glossaryHit = $this->glossary->lookup($translation->source_text);

        if ($glossaryHit !== null) {
            $translation->update([
                'translated_text' => $glossaryHit,
                'status' => MarketplaceTranslationStatus::AutoTranslated,
                'provider' => 'glossary',
                'translated_at' => now(),
                'error_message' => null,
            ]);

            return $translation->fresh();
        }

        try {
            $translated = $translator->translate(
                $translation->source_text,
                $field,
                $sourceLocale,
                $translation->locale,
                $translation->marketplace,
            );

            $translation->update([
                'translated_text' => $translated,
                'status' => MarketplaceTranslationStatus::AutoTranslated,
                'provider' => $translator->providerName(),
                'translated_at' => now(),
                'error_message' => null,
            ]);
        } catch (\Throwable $exception) {
            $translation->update([
                'status' => MarketplaceTranslationStatus::Failed,
                'provider' => $translator->providerName(),
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        return $translation->fresh();
    }

    public function lockKey(MarketplaceTranslation $translation): string
    {
        return sprintf(
            'marketplace-translation:%s:%s:%s:%s:%s',
            $translation->marketplace ?? 'any',
            $translation->locale,
            $translation->field,
            $translation->translatable_type ?? 'none',
            $translation->translatable_id ?? '0',
        );
    }

    public function withLock(MarketplaceTranslation $translation, callable $callback): mixed
    {
        $lock = Cache::lock($this->lockKey($translation), (int) config('marketplace.translations.lock_seconds', 300));

        if (! $lock->get()) {
            return null;
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    private function shouldSkipTranslation(string $sourceText, string $field): bool
    {
        $canonical = $this->canonicalField($field);

        if (in_array($canonical, ['sku', 'barcode', 'brand', 'vendor', 'price', 'quantity'], true)) {
            return true;
        }

        return $this->glossary->isProtectedValue($sourceText, $canonical);
    }

    private function normalizeSource(string $sourceText): string
    {
        return trim($sourceText);
    }
}
