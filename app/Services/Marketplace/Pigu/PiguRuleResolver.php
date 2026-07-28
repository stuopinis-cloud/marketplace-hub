<?php

namespace App\Services\Marketplace\Pigu;

use App\Models\PiguDimensionRule;
use App\Models\PiguPropertyRule;
use App\Models\Product;
use App\Services\Marketplace\CategoryResolver;

class PiguRuleResolver
{
    /**
     * @return array{weight_kg: float, length_m: float, width_m: float, height_m: float}|null
     */
    public function resolveDimensions(Product $product, ?string $preferredHandle = null): ?array
    {
        $product->loadMissing('sourceCategories');
        $rule = $this->bestDimensionRule($product, $preferredHandle);

        if ($rule === null) {
            return null;
        }

        return [
            'weight_kg' => (float) $rule->weight_kg,
            'length_m' => (float) $rule->length_m,
            'width_m' => (float) $rule->width_m,
            'height_m' => (float) $rule->height_m,
        ];
    }

    /**
     * @return list<array{property_name: string, property_id: ?int, value: string}>
     */
    public function resolveProperties(
        Product $product,
        int|string $piguCategoryId,
        ?string $preferredHandle = null,
    ): array {
        $product->loadMissing('sourceCategories');

        $rules = PiguPropertyRule::query()
            ->where('pigu_category_id', (int) $piguCategoryId)
            ->where('enabled', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        $resolved = [];

        foreach ($rules as $rule) {
            if ($preferredHandle !== null
                && $rule->match_type === 'collection'
                && trim((string) $rule->match_value) !== trim($preferredHandle)
            ) {
                continue;
            }

            if (! $this->ruleMatchesProduct($product, $rule->match_type, $rule->match_value)) {
                continue;
            }

            $value = $this->resolvePropertyValue($product, $rule);

            if ($value === null || $value === '') {
                continue;
            }

            if (isset($resolved[$rule->property_name])) {
                continue;
            }

            $resolved[$rule->property_name] = [
                'property_name' => $rule->property_name,
                'property_id' => $rule->property_id,
                'value' => $value,
            ];
        }

        return array_values($resolved);
    }

    private function bestDimensionRule(Product $product, ?string $preferredHandle = null): ?PiguDimensionRule
    {
        $rules = PiguDimensionRule::query()
            ->where('enabled', true)
            ->when(
                $preferredHandle !== null,
                fn ($query) => $query->where('match_type', 'collection')->where('match_value', trim($preferredHandle)),
            )
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            if ($this->ruleMatchesProduct($product, $rule->match_type, $rule->match_value)) {
                return $rule;
            }
        }

        return null;
    }

    private function ruleMatchesProduct(Product $product, string $matchType, string $matchValue): bool
    {
        $normalized = CategoryResolver::normalizeComparisonValue($matchValue);

        if ($normalized === '') {
            return false;
        }

        return match ($matchType) {
            'collection' => $product->sourceCategories
                ->where('type', 'collection')
                ->contains(function ($category) use ($normalized): bool {
                    return CategoryResolver::normalizeComparisonValue($category->handle) === $normalized
                        || CategoryResolver::normalizeComparisonValue($category->name) === $normalized
                        || CategoryResolver::normalizeComparisonValue($category->external_id) === $normalized;
                }),
            'product_type' => CategoryResolver::normalizeComparisonValue($product->product_type) === $normalized
                || $product->sourceCategories
                    ->where('type', 'product_type')
                    ->contains(fn ($category): bool => CategoryResolver::normalizeComparisonValue($category->name) === $normalized
                        || CategoryResolver::normalizeComparisonValue($category->handle) === $normalized),
            'tag' => $product->sourceCategories
                ->where('type', 'tag')
                ->contains(fn ($category): bool => CategoryResolver::normalizeComparisonValue($category->name) === $normalized),
            'manual' => CategoryResolver::normalizeComparisonValue($product->category) === $normalized,
            default => false,
        };
    }

    private function resolvePropertyValue(Product $product, PiguPropertyRule $rule): ?string
    {
        if ($rule->value_source === PiguPropertyRule::VALUE_SOURCE_VENDOR) {
            if (filled($product->vendor)) {
                return (string) $product->vendor;
            }

            return filled($product->brand) ? (string) $product->brand : null;
        }

        return filled($rule->value_name) ? (string) $rule->value_name : null;
    }
}
