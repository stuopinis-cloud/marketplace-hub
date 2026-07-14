<?php

namespace App\Services\Marketplace;

use App\Models\CategoryMapping;
use App\Models\MarketplaceChannel;
use App\Models\Product;
use App\Models\SourceCategory;

class CategoryResolver
{
    /** @var array<string, int> */
    private const SOURCE_TYPE_ORDER = [
        'collection' => 0,
        'product_type' => 1,
        'tag' => 2,
        'manual' => 3,
    ];

    public function resolve(Product $product, MarketplaceChannel $channel): ?string
    {
        return $this->explain($product, $channel)['resolved_category'];
    }

    public function mappingMatchesProduct(CategoryMapping $mapping, Product $product): bool
    {
        $product->loadMissing('sourceCategories');

        return $this->findMatchingSourceCategory($mapping, $product) !== false;
    }

    public function countMatchingProducts(CategoryMapping $mapping): int
    {
        $count = 0;

        Product::query()
            ->select('id')
            ->chunkById(500, function ($products) use ($mapping, &$count): void {
                $loaded = Product::query()
                    ->with('sourceCategories')
                    ->whereIn('id', $products->pluck('id'))
                    ->get();

                foreach ($loaded as $product) {
                    if ($this->mappingMatchesProduct($mapping, $product)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    public static function normalizeComparisonValue(?string $value): string
    {
        if (blank($value)) {
            return '';
        }

        $normalized = mb_strtolower(trim((string) $value));
        $normalized = str_replace(['→', '—', '–'], '->', $normalized);
        $normalized = preg_replace('/\s*->\s*/', ' -> ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    /**
     * @return array{
     *     resolved_category: ?string,
     *     source: ?string,
     *     matched_mapping_id: ?int,
     *     matched_source_type: ?string,
     *     matched_source_value: ?string,
     *     fallback_used: bool,
     *     details: array<string, mixed>
     * }
     */
    public function explain(Product $product, MarketplaceChannel $channel, ?\Illuminate\Support\Collection $preloadedMappings = null): array
    {
        $mappingMatch = $preloadedMappings !== null
            ? $this->resolveFromMappingCollection($product, $preloadedMappings)
            : $this->resolveFromMappings($product, $channel);

        if ($mappingMatch !== null) {
            return $mappingMatch;
        }

        if ($this->requireCategoryMapping($channel)) {
            return $this->buildResult(
                resolvedCategory: null,
                source: null,
                fallbackUsed: false,
                details: ['message' => 'Missing required category mapping.'],
            );
        }

        return $this->resolveFallback($product, $channel);
    }

    /**
     * @return array{
     *     resolved_category: string,
     *     source: string,
     *     matched_mapping_id: int,
     *     matched_source_type: string,
     *     matched_source_value: string,
     *     fallback_used: bool,
     *     details: array<string, mixed>
     * }|null
     */
    private function resolveFromMappings(Product $product, MarketplaceChannel $channel): ?array
    {
        $mappings = CategoryMapping::query()
            ->where('marketplace_channel_id', $channel->id)
            ->where('enabled', true)
            ->get();

        return $this->resolveFromMappingCollection($product, $mappings);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, CategoryMapping>  $mappings
     * @return array{
     *     resolved_category: string,
     *     source: string,
     *     matched_mapping_id: int,
     *     matched_source_type: string,
     *     matched_source_value: string,
     *     fallback_used: bool,
     *     details: array<string, mixed>
     * }|null
     */
    private function resolveFromMappingCollection(Product $product, \Illuminate\Support\Collection $mappings): ?array
    {
        $product->loadMissing('sourceCategories');

        if ($mappings->isEmpty()) {
            return null;
        }

        $matches = [];

        foreach ($mappings as $mapping) {
            $matchedSourceCategory = $this->findMatchingSourceCategory($mapping, $product);

            if ($matchedSourceCategory === false) {
                continue;
            }

            $matches[] = [
                'mapping' => $mapping,
                'source_category' => $matchedSourceCategory,
                'priority' => (int) $mapping->priority,
                'source_type_order' => self::SOURCE_TYPE_ORDER[$mapping->source_type] ?? 99,
            ];
        }

        if ($matches === []) {
            return null;
        }

        usort($matches, function (array $left, array $right): int {
            if ($left['priority'] !== $right['priority']) {
                return $left['priority'] <=> $right['priority'];
            }

            return $left['source_type_order'] <=> $right['source_type_order'];
        });

        /** @var array{mapping: CategoryMapping, source_category: SourceCategory|null, priority: int, source_type_order: int} $bestMatch */
        $bestMatch = $matches[0];
        $mapping = $bestMatch['mapping'];

        return $this->buildResult(
            resolvedCategory: (string) $mapping->target_category_path,
            source: 'mapping',
            matchedMappingId: $mapping->id,
            matchedSourceType: (string) $mapping->source_type,
            matchedSourceValue: (string) $mapping->source_value,
            fallbackUsed: false,
            details: [
                'message' => 'Matched category mapping.',
                'source_category_id' => $bestMatch['source_category']?->id,
                'source_category_name' => $bestMatch['source_category']?->name,
                'source_category_handle' => $bestMatch['source_category']?->handle,
                'priority' => $mapping->priority,
            ],
        );
    }

    /**
     * @return array{
     *     resolved_category: ?string,
     *     source: ?string,
     *     matched_mapping_id: ?int,
     *     matched_source_type: ?string,
     *     matched_source_value: ?string,
     *     fallback_used: bool,
     *     details: array<string, mixed>
     * }
     */
    private function resolveFallback(Product $product, MarketplaceChannel $channel): array
    {
        if (filled($product->category)) {
            return $this->buildResult(
                resolvedCategory: (string) $product->category,
                source: 'product.category',
                fallbackUsed: true,
                details: ['message' => 'No category mapping found, fell back to product.category.'],
            );
        }

        if (filled($product->product_type)) {
            return $this->buildResult(
                resolvedCategory: (string) $product->product_type,
                source: 'product.product_type',
                matchedSourceType: 'product_type',
                matchedSourceValue: (string) $product->product_type,
                fallbackUsed: true,
                details: ['message' => 'No category mapping found, fell back to product.product_type.'],
            );
        }

        $defaultCategory = $channel->config['default_category'] ?? null;

        if (filled($defaultCategory)) {
            return $this->buildResult(
                resolvedCategory: (string) $defaultCategory,
                source: 'default_category',
                fallbackUsed: true,
                details: ['message' => 'No category mapping found, fell back to default_category.'],
            );
        }

        return $this->buildResult(
            resolvedCategory: null,
            source: null,
            fallbackUsed: false,
            details: ['message' => 'No category could be resolved.'],
        );
    }

    /**
     * @return SourceCategory|false|null
     */
    private function findMatchingSourceCategory(CategoryMapping $mapping, Product $product): SourceCategory|false|null
    {
        $mappingValue = self::normalizeComparisonValue($mapping->source_value);

        return match ($mapping->source_type) {
            'collection' => $this->matchCollection($product, $mappingValue),
            'product_type' => $this->matchProductType($product, $mappingValue),
            'tag' => $this->matchTag($product, $mappingValue),
            'manual' => $this->matchManual($product, $mappingValue),
            default => false,
        };
    }

  /**
     * @return SourceCategory|false
     */
    private function matchCollection(Product $product, string $mappingValue): SourceCategory|false
    {
        foreach ($product->sourceCategories->where('type', 'collection') as $sourceCategory) {
            if ($this->valuesMatch($mappingValue, $sourceCategory->name)
                || $this->valuesMatch($mappingValue, $sourceCategory->handle)) {
                return $sourceCategory;
            }
        }

        return false;
    }

    /**
     * @return SourceCategory|false|null
     */
    private function matchProductType(Product $product, string $mappingValue): SourceCategory|false|null
    {
        if ($this->valuesMatch($mappingValue, $product->product_type)) {
            return null;
        }

        foreach ($product->sourceCategories->where('type', 'product_type') as $sourceCategory) {
            if ($this->valuesMatch($mappingValue, $sourceCategory->name)) {
                return $sourceCategory;
            }
        }

        return false;
    }

    /**
     * @return SourceCategory|false
     */
    private function matchTag(Product $product, string $mappingValue): SourceCategory|false
    {
        foreach ($product->sourceCategories->where('type', 'tag') as $sourceCategory) {
            if ($this->valuesMatch($mappingValue, $sourceCategory->name)) {
                return $sourceCategory;
            }
        }

        return false;
    }

    /**
     * @return SourceCategory|false|null
     */
    private function matchManual(Product $product, string $mappingValue): SourceCategory|false|null
    {
        if ($this->valuesMatch($mappingValue, $product->category)) {
            return null;
        }

        return false;
    }

    private function valuesMatch(string $mappingValue, ?string $candidate): bool
    {
        if (blank($candidate)) {
            return false;
        }

        return self::normalizeComparisonValue($candidate) === $mappingValue;
    }

    private function requireCategoryMapping(MarketplaceChannel $channel): bool
    {
        return (bool) ($channel->config['require_category_mapping'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{
     *     resolved_category: ?string,
     *     source: ?string,
     *     matched_mapping_id: ?int,
     *     matched_source_type: ?string,
     *     matched_source_value: ?string,
     *     fallback_used: bool,
     *     details: array<string, mixed>
     * }
     */
    private function buildResult(
        ?string $resolvedCategory,
        ?string $source,
        bool $fallbackUsed,
        array $details = [],
        ?int $matchedMappingId = null,
        ?string $matchedSourceType = null,
        ?string $matchedSourceValue = null,
    ): array {
        return [
            'resolved_category' => $resolvedCategory,
            'source' => $source,
            'matched_mapping_id' => $matchedMappingId,
            'matched_source_type' => $matchedSourceType,
            'matched_source_value' => $matchedSourceValue,
            'fallback_used' => $fallbackUsed,
            'details' => $details,
        ];
    }
}
