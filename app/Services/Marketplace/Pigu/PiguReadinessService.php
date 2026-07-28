<?php

namespace App\Services\Marketplace\Pigu;

use App\Enums\MarketplaceTranslationStatus;
use App\Models\CategoryMapping;
use App\Models\MarketplaceChannel;
use App\Models\MarketplaceTranslation;
use App\Models\PiguCategoryField;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SourceCategory;
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
     * @param  list<string>|null  $easyEligibleHandles  When set, only these collection handles may resolve a Pigu category.
     * @return array{
     *     exportable: bool,
     *     issues: list<array{code: string, message: string, severity: string}>,
     *     category_id: ?string,
     *     category_name: ?string,
     *     chosen_source_handle: ?string,
     *     warnings: list<string>,
     *     debug: array<string, mixed>
     * }
     */
    public function analyzeProduct(
        Product $product,
        MarketplaceChannel $channel,
        ?array $easyEligibleHandles = null,
    ): array {
        $issues = [];
        $warnings = [];
        $easyOnly = $easyEligibleHandles !== null;

        $category = $this->resolvePiguCategory($product, $channel, $easyEligibleHandles);
        $debug = $category['debug'];

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

        $manufacturerName = filled($product->vendor)
            ? (string) $product->vendor
            : (filled($product->brand) ? (string) $product->brand : null);

        if ($manufacturerName === null || trim($manufacturerName) === '') {
            $warnings[] = 'Manufacturer name is missing (product vendor/brand empty).';
            $issues[] = $this->issue(
                'missing_manufacturer_name',
                'Manufacturer name is missing (product vendor/brand empty).',
                'warning',
            );
        }

        if ($category['id'] !== null) {
            $missingProperties = $this->missingMandatoryProperties(
                (int) $category['id'],
                $product,
                $category['chosen_source_handle'],
            );

            foreach ($missingProperties as $fieldTitle) {
                $warnings[] = "Mandatory Pigu property missing: {$fieldTitle}";
                $issues[] = $this->issue(
                    'missing_required_property',
                    "Mandatory Pigu property missing: {$fieldTitle}",
                    $easyOnly ? 'error' : 'warning',
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
            'chosen_source_handle' => $category['chosen_source_handle'],
            'manufacturer_name' => $manufacturerName,
            'warnings' => $warnings,
            'debug' => $debug,
        ];
    }

    /**
     * @param  list<string>|null  $easyEligibleHandles
     * @return array{
     *     id: ?string,
     *     name: ?string,
     *     mapping: ?CategoryMapping,
     *     chosen_source_handle: ?string,
     *     debug: array<string, mixed>
     * }
     */
    public function resolvePiguCategory(
        Product $product,
        MarketplaceChannel $channel,
        ?array $easyEligibleHandles = null,
    ): array {
        $product->loadMissing('sourceCategories');

        $sourceCategories = $product->sourceCategories->map(fn (SourceCategory $category): array => [
            'id' => $category->id,
            'type' => $category->type,
            'name' => $category->name,
            'handle' => $category->handle,
            'external_id' => $category->external_id,
        ])->values()->all();

        $easyOnly = $easyEligibleHandles !== null;
        $eligible = $easyOnly
            ? collect($easyEligibleHandles)->map(fn ($handle): string => trim((string) $handle))->filter()->unique()->values()
            : null;

        if ($easyOnly) {
            $mappings = CategoryMapping::query()
                ->where('marketplace_channel_id', $channel->id)
                ->where('enabled', true)
                ->where('export_enabled', true)
                ->where('source_type', 'collection')
                ->whereIn('source_value', $eligible->all())
                ->get();

            $explained = $this->categories->explain($product, $channel, $mappings);
        } else {
            $explained = $this->categories->explain($product, $channel);
        }

        $mappingId = $explained['matched_mapping_id'] ?? null;
        $chosenHandle = filled($explained['matched_source_value'] ?? null)
            ? (string) $explained['matched_source_value']
            : ($explained['details']['source_category_handle'] ?? null);

        $debug = [
            'marketplace_channel_id' => $channel->id,
            'marketplace_channel_name' => $channel->name,
            'source_categories' => $sourceCategories,
            'mapping_matched' => $mappingId !== null,
            'matched_mapping_id' => $mappingId,
            'matched_source_type' => $explained['matched_source_type'] ?? null,
            'matched_source_value' => $explained['matched_source_value'] ?? null,
            'resolver_source' => $explained['source'] ?? null,
            'export_enabled' => null,
            'chosen_source_handle' => $chosenHandle,
            'chosen_category_id' => null,
            'easy_only_allowed' => $easyOnly,
            'easy_eligible_handles' => $eligible?->all() ?? [],
        ];

        if ($mappingId === null) {
            $debug['available_mappings'] = CategoryMapping::query()
                ->where('marketplace_channel_id', $channel->id)
                ->where('enabled', true)
                ->when($easyOnly, function ($query) use ($eligible): void {
                    $query->where('source_type', 'collection')
                        ->where('export_enabled', true)
                        ->whereIn('source_value', $eligible->all());
                })
                ->get(['id', 'source_type', 'source_value', 'export_enabled', 'target_category_id', 'target_category_name'])
                ->map(fn (CategoryMapping $mapping): array => [
                    'id' => $mapping->id,
                    'source_type' => $mapping->source_type,
                    'source_value' => $mapping->source_value,
                    'export_enabled' => $mapping->export_enabled,
                    'target_category_id' => $mapping->target_category_id,
                    'target_category_name' => $mapping->target_category_name,
                ])
                ->all();

            return [
                'id' => null,
                'name' => null,
                'mapping' => null,
                'chosen_source_handle' => null,
                'debug' => $debug,
            ];
        }

        $mapping = CategoryMapping::query()->find($mappingId);

        if ($mapping === null || $mapping->export_enabled === false) {
            $debug['export_enabled'] = $mapping?->export_enabled;
            $debug['mapping_matched'] = false;
            $debug['ignored_reason'] = $mapping === null ? 'mapping_missing' : 'export_disabled';

            return [
                'id' => null,
                'name' => null,
                'mapping' => null,
                'chosen_source_handle' => null,
                'debug' => $debug,
            ];
        }

        // Defense in depth: never accept a non-easy collection handle under --easy-only.
        if ($easyOnly && ($mapping->source_type !== 'collection' || ! $eligible->contains(trim((string) $mapping->source_value)))) {
            $debug['mapping_matched'] = false;
            $debug['ignored_reason'] = 'not_easy_eligible';

            return [
                'id' => null,
                'name' => null,
                'mapping' => null,
                'chosen_source_handle' => null,
                'debug' => $debug,
            ];
        }

        $debug['export_enabled'] = true;
        $chosenHandle = trim((string) $mapping->source_value);

        $id = filled($mapping->target_category_id) ? (string) $mapping->target_category_id : null;
        $name = filled($mapping->target_category_name) ? (string) $mapping->target_category_name : null;
        $pathName = null;

        if (filled($mapping->target_category_path)
            && preg_match('/^(\d+)\s*[|:]\s*(.+)$/u', (string) $mapping->target_category_path, $matches) === 1
        ) {
            $id ??= $matches[1];
            $pathName = trim($matches[2]);
            $name ??= $pathName;
        }

        if ($id !== null) {
            $name = $this->enrichCategoryName((int) $id, $name, $pathName);
        }

        $debug['chosen_source_handle'] = $chosenHandle;
        $debug['chosen_category_id'] = $id;

        return [
            'id' => $id,
            'name' => $name,
            'mapping' => $mapping,
            'chosen_source_handle' => $chosenHandle,
            'debug' => $debug,
        ];
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
    public function missingMandatoryProperties(
        int $categoryId,
        Product $product,
        ?string $preferredHandle = null,
    ): array {
        $missing = [];
        $ruleResolver = new PiguRuleResolver;
        $resolvedRules = collect($ruleResolver->resolveProperties($product, $categoryId, $preferredHandle))
            ->keyBy(fn (array $property): string => mb_strtolower(trim($property['property_name'])));

        $fields = PiguCategoryField::query()
            ->where('lowest_category_id', $categoryId)
            ->where('mandatory', true)
            ->get();

        foreach ($fields as $field) {
            $fieldTitle = trim((string) ($field->field_title ?: $field->field_title_en ?: $field->field_id));
            $lookupKeys = array_filter([
                mb_strtolower((string) $field->field_title),
                mb_strtolower((string) $field->field_title_en),
            ]);

            $fromRules = null;
            foreach ($lookupKeys as $key) {
                if ($key !== '' && $resolvedRules->has($key)) {
                    $fromRules = $resolvedRules->get($key)['value'] ?? null;
                    break;
                }
            }

            $value = filled($fromRules) ? (string) $fromRules : $this->guessPropertyValue($product, $field);

            if ($value === null || $value === '') {
                $missing[] = $fieldTitle !== '' ? $fieldTitle : (string) $field->field_id;
            }
        }

        return $missing;
    }

    private function enrichCategoryName(int $categoryId, ?string $currentName, ?string $pathName): ?string
    {
        $field = PiguCategoryField::query()
            ->where('lowest_category_id', $categoryId)
            ->orderBy('id')
            ->first();

        if ($field === null) {
            if (filled($pathName) && str_contains($pathName, '>')) {
                return $pathName;
            }

            return $currentName ?? $pathName;
        }

        $parts = array_values(array_filter([
            filled($field->top_category_title) ? trim((string) $field->top_category_title) : null,
            filled($field->lowest_category_title) ? trim((string) $field->lowest_category_title) : null,
        ]));

        if ($parts === []) {
            return $currentName ?? $pathName;
        }

        $enriched = implode(' > ', array_unique($parts));

        // Prefer richer path when available.
        if ($currentName === null || $currentName === '' || ! str_contains((string) $currentName, '>')) {
            return $enriched;
        }

        return $currentName;
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
