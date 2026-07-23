<?php

namespace App\Services\Marketplace\Pigu;

use App\Enums\MarketplaceTranslationStatus;
use App\Models\CategoryMapping;
use App\Models\MarketplaceChannel;
use App\Models\MarketplaceTranslation;
use App\Models\PiguCategoryField;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Marketplace\CategoryResolver;
use App\Services\Marketplace\ProductAvailabilityResolver;
use App\Services\Marketplace\Translations\MarketplaceTranslationService;
use App\Services\Marketplace\Varle\VarleVariantPresenter;

class PiguReadinessService
{
    public function __construct(
        private readonly CategoryResolver $categories,
        private readonly MarketplaceTranslationService $translations,
        private readonly ProductAvailabilityResolver $availability,
        private readonly PiguDescriptionSanitizer $sanitizer = new PiguDescriptionSanitizer,
    ) {}

    /**
     * @return array{
     *     exportable: bool,
     *     issues: list<array{code: string, message: string, severity: string}>,
     *     category_id: ?string,
     *     category_name: ?string,
     *     warnings: list<string>
     * }
     */
    public function analyzeProduct(Product $product, MarketplaceChannel $channel): array
    {
        $issues = [];
        $warnings = [];

        $category = $this->resolvePiguCategory($product, $channel);

        if ($category['id'] === null || $category['name'] === null) {
            $issues[] = $this->issue('missing_pigu_category_mapping', 'Product is missing a Pigu category mapping.');
        }

        if ($category['id'] === null) {
            $issues[] = $this->issue('missing_category_id', 'Pigu category-id is missing.');
        }

        if ($category['name'] === null) {
            $issues[] = $this->issue('missing_category_name', 'Pigu category-name is missing.');
        }

        if (! filled(trim((string) $product->title))) {
            $issues[] = $this->issue('missing_title', 'Product title is missing.');
        }

        $description = $this->sanitizer->sanitize((string) ($product->description_html ?? ''));
        if ($description === '') {
            $issues[] = $this->issue('missing_long_description', 'Product long description is missing.');
        }

        if ($category['id'] !== null) {
            foreach ($this->missingMandatoryProperties((int) $category['id'], $product) as $fieldTitle) {
                $warnings[] = "Mandatory Pigu property missing: {$fieldTitle}";
                $issues[] = $this->issue(
                    'missing_required_property',
                    "Mandatory Pigu property missing: {$fieldTitle}",
                    'warning',
                );
            }
        }

        $blocking = array_values(array_filter(
            $issues,
            fn (array $issue): bool => ($issue['severity'] ?? 'error') === 'error',
        ));

        return [
            'exportable' => $blocking === [],
            'issues' => $issues,
            'category_id' => $category['id'],
            'category_name' => $category['name'],
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array{id: ?string, name: ?string, mapping: ?CategoryMapping}
     */
    public function resolvePiguCategory(Product $product, MarketplaceChannel $channel): array
    {
        $explained = $this->categories->explain($product, $channel);
        $mappingId = $explained['matched_mapping_id'] ?? null;

        if ($mappingId === null) {
            return ['id' => null, 'name' => null, 'mapping' => null];
        }

        $mapping = CategoryMapping::query()->find($mappingId);

        if ($mapping === null || $mapping->export_enabled === false) {
            return ['id' => null, 'name' => null, 'mapping' => null];
        }

        $id = filled($mapping->target_category_id) ? (string) $mapping->target_category_id : null;
        $name = filled($mapping->target_category_name) ? (string) $mapping->target_category_name : null;

        // Only accept "id|name" / "id:name" path fallback — never a plain label without an ID.
        if (($id === null || $name === null)
            && filled($mapping->target_category_path)
            && preg_match('/^(\d+)\s*[|:]\s*(.+)$/u', (string) $mapping->target_category_path, $matches) === 1
        ) {
            $id ??= $matches[1];
            $name ??= trim($matches[2]);
        }

        return ['id' => $id, 'name' => $name, 'mapping' => $mapping];
    }

    public function usableEnglishTranslation(Product $product, string $field): ?string
    {
        $source = $field === MarketplaceTranslation::FIELD_TITLE
            ? (string) $product->title
            : (string) ($product->description_html ?? '');

        foreach (['pigu', 'ebay'] as $marketplace) {
            $translation = $this->translations->getTranslation(
                $product,
                $field,
                'en',
                $source,
                $marketplace,
            );

            if ($translation !== null
                && in_array($translation->status->value, MarketplaceTranslationStatus::usableValues(), true)
                && filled($translation->translated_text)
            ) {
                return (string) $translation->translated_text;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function missingMandatoryProperties(int $categoryId, Product $product): array
    {
        $missing = [];

        $fields = PiguCategoryField::query()
            ->where('lowest_category_id', $categoryId)
            ->where('mandatory', true)
            ->get();

        foreach ($fields as $field) {
            $value = $this->guessPropertyValue($product, $field);

            if ($value === null || $value === '') {
                $missing[] = (string) ($field->field_title ?: $field->field_id);
            }
        }

        return $missing;
    }

    public function guessPropertyValue(Product $product, PiguCategoryField $field): ?string
    {
        $title = mb_strtolower((string) ($field->field_title_en ?: $field->field_title));

        if (str_contains($title, 'brand') || str_contains($title, 'manufacturer') || str_contains(mb_strtolower((string) $field->field_title), 'ženklas')) {
            return filled($product->vendor) ? (string) $product->vendor : (filled($product->brand) ? (string) $product->brand : null);
        }

        if (str_contains($title, 'color') || str_contains($title, 'colour') || str_contains(mb_strtolower((string) $field->field_title), 'spalva')) {
            $variant = $product->variants->first();

            return $variant ? VarleVariantPresenter::colorValue($product, $variant) : null;
        }

        if (str_contains($title, 'size') || str_contains(mb_strtolower((string) $field->field_title), 'dydis')) {
            $variant = $product->variants->first();
            if ($variant === null) {
                return null;
            }

            $size = collect(VarleVariantPresenter::getMeaningfulNonColorOptions($product, $variant))
                ->first(fn (array $option): bool => VarleVariantPresenter::isSizeOptionName($option['name']));

            return $size['value'] ?? null;
        }

        if (str_contains($title, 'type') || str_contains(mb_strtolower((string) $field->field_title), 'tipas')) {
            return filled($product->product_type) ? (string) $product->product_type : null;
        }

        if (str_contains($title, 'material') || str_contains(mb_strtolower((string) $field->field_title), 'medžiaga')) {
            return $this->extractMaterial($product);
        }

        return null;
    }

    public function extractMaterial(Product $product): ?string
    {
        $payload = is_array($product->raw_payload) ? $product->raw_payload : [];
        $tags = $payload['tags'] ?? [];

        if (is_string($tags)) {
            $tags = array_map('trim', explode(',', $tags));
        }

        if (! is_array($tags)) {
            return null;
        }

        foreach ($tags as $tag) {
            if (! is_string($tag)) {
                continue;
            }

            if (str_starts_with(mb_strtolower($tag), 'material:')) {
                return trim(substr($tag, 9));
            }
        }

        return null;
    }

    /**
     * @return array{exportable: bool, quantity: int, issue_code: ?string}
     */
    public function variantAvailability(ProductVariant $variant): array
    {
        $resolved = $this->availability->resolve($variant);

        return [
            'exportable' => (bool) $resolved['exportable'] && (int) $resolved['quantity'] > 0,
            'quantity' => (int) $resolved['quantity'],
            'issue_code' => $resolved['issue_code'] ?? (($resolved['exportable'] ?? false) ? null : 'quantity_not_exportable'),
        ];
    }

    /**
     * @return array{code: string, message: string, severity: string}
     */
    private function issue(string $code, string $message, string $severity = 'error'): array
    {
        return compact('code', 'message', 'severity');
    }
}
