<?php

namespace App\Services\Marketplace\Varle;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class VarleVariantPresenter
{
    public static function isColorOptionName(string $name): bool
    {
        $normalized = mb_strtolower(trim($name));

        return in_array($normalized, [
            'color',
            'colour',
            'spalva',
            'spalvos',
            'spalvų',
            'spalvu',
            'farba',
            'farbe',
            'couleur',
        ], true);
    }

    public static function isSizeOptionName(string $name): bool
    {
        $normalized = mb_strtolower(trim($name));

        return in_array($normalized, [
            'size',
            'dydis',
            'dydžiai',
            'dydziai',
        ], true);
    }

    public static function productHasColorOption(Product $product): bool
    {
        if (self::optionNames($product)->contains(
            fn (string $name): bool => self::isColorOptionName($name),
        )) {
            return true;
        }

        foreach ($product->variants as $variant) {
            foreach (self::optionsFromVariantColumns($variant) as $option) {
                if (self::isColorOptionName($option['name'])) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function productHasSizeOption(Product $product): bool
    {
        if (self::optionNames($product)->contains(
            fn (string $name): bool => self::isSizeOptionName($name),
        )) {
            return true;
        }

        foreach ($product->variants as $variant) {
            foreach (self::optionsFromVariantColumns($variant) as $option) {
                if (self::isSizeOptionName($option['name'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{name: string, value: string}|null
     */
    public static function getColorOption(Product $product, ProductVariant $variant): ?array
    {
        foreach (self::getSelectedOptions($product, $variant) as $option) {
            if (self::isColorOptionName($option['name'])) {
                return $option;
            }
        }

        return null;
    }

    /**
     * @return array<int, array{name: string, value: string}>
     */
    public static function getNonColorOptions(Product $product, ProductVariant $variant): array
    {
        return array_values(array_filter(
            self::getSelectedOptions($product, $variant),
            fn (array $option): bool => ! self::isColorOptionName($option['name']),
        ));
    }

    /**
     * @param  array<int, array{variant: ProductVariant, quantity: int}>|Collection<int, array{variant: ProductVariant, quantity: int}>  $validVariants
     */
    public static function shouldOutputVariants(Product $product, array|Collection $validVariants): bool
    {
        $rows = $validVariants instanceof Collection ? $validVariants->all() : $validVariants;

        if (count($rows) > 1) {
            return true;
        }

        if ($rows === []) {
            return false;
        }

        return self::getNonColorOptions($product, $rows[0]['variant']) !== [];
    }

    public static function colorValue(Product $product, ProductVariant $variant): ?string
    {
        $colorOption = self::getColorOption($product, $variant);

        return $colorOption !== null ? $colorOption['value'] : null;
    }

    public static function nonColorGroupTitle(Product $product): string
    {
        $names = self::nonColorOptionNames($product);

        if ($names->isEmpty()) {
            return 'Dydis';
        }

        return $names->implode(' / ');
    }

    public static function variantDisplayTitle(Product $product, ProductVariant $variant): string
    {
        $nonColorOptions = self::getNonColorOptions($product, $variant);

        if ($nonColorOptions !== []) {
            return collect($nonColorOptions)
                ->pluck('value')
                ->implode(' / ');
        }

        return filled($variant->title) ? (string) $variant->title : 'Default';
    }

    public static function sizeGroupTitle(Product $product): string
    {
        return self::nonColorGroupTitle($product);
    }

    public static function sizeTitle(Product $product, ProductVariant $variant): string
    {
        return self::variantDisplayTitle($product, $variant);
    }

    public static function colorSlug(string $colorValue): string
    {
        return Str::slug($colorValue);
    }

    /**
     * @param  array<int, array{variant: ProductVariant, quantity: int}>  $validVariants
     * @param  array<string, mixed>  $config
     * @return array{
     *     urls: array<int, string>,
     *     used_fallback: bool,
     *     variant_image_url: ?string
     * }
     */
    public static function resolveExportImageUrls(Product $product, array $validVariants, array $config): array
    {
        $urls = [];
        $seen = [];
        $firstVariantImage = null;

        foreach ($validVariants as $row) {
            $url = filled($row['variant']->image_url) ? (string) $row['variant']->image_url : null;

            if ($firstVariantImage === null && $url !== null) {
                $firstVariantImage = $url;
            }

            if ($url === null || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $urls[] = $url;
        }

        if ($urls !== []) {
            return [
                'urls' => $urls,
                'used_fallback' => false,
                'variant_image_url' => $firstVariantImage,
            ];
        }

        if ($config['allow_fallback_product_images'] ?? false) {
            return [
                'urls' => $product->images
                    ->sortBy('position')
                    ->pluck('url')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all(),
                'used_fallback' => true,
                'variant_image_url' => null,
            ];
        }

        return [
            'urls' => [],
            'used_fallback' => false,
            'variant_image_url' => null,
        ];
    }

    public static function missingExportImagesMessage(array $config): string
    {
        return ($config['allow_fallback_product_images'] ?? false)
            ? 'No images'
            : 'No variant-specific images found';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function productHasExportableImages(Product $product, array $config): bool
    {
        if ($product->variants->contains(fn (ProductVariant $variant): bool => filled($variant->image_url))) {
            return true;
        }

        return ($config['allow_fallback_product_images'] ?? false) && $product->images->isNotEmpty();
    }

    /**
     * @param  array<int, ProductVariant>  $variants
     * @return array<string, array<int, ProductVariant>>
     */
    public static function groupVariantsByColor(Product $product, array $variants): array
    {
        $groups = [];

        foreach ($variants as $variant) {
            $colorValue = self::colorValue($product, $variant) ?? '';
            $groups[$colorValue][] = $variant;
        }

        return $groups;
    }

    /**
     * @return array<int, array{name: string, value: string}>
     */
    public static function optionsFromVariantColumns(ProductVariant $variant): array
    {
        $options = [];

        for ($index = 1; $index <= 3; $index++) {
            $name = $variant->{"option{$index}_name"};
            $value = $variant->{"option{$index}_value"} ?? $variant->{"option{$index}"};

            if (filled($name) && filled($value)) {
                $options[] = [
                    'name' => (string) $name,
                    'value' => (string) $value,
                ];
            }
        }

        return $options;
    }

    /**
     * @return Collection<int, string>
     */
    public static function nonColorOptionNames(Product $product): Collection
    {
        $fromProduct = collect(self::productOptions($product))
            ->filter(fn ($option) => is_array($option))
            ->pluck('name')
            ->filter()
            ->map(fn ($name) => (string) $name)
            ->reject(fn (string $name): bool => self::isColorOptionName($name))
            ->values();

        if ($fromProduct->isNotEmpty()) {
            return $fromProduct;
        }

        foreach ($product->variants as $variant) {
            $names = collect(self::optionsFromVariantColumns($variant))
                ->reject(fn (array $option): bool => self::isColorOptionName($option['name']))
                ->pluck('name')
                ->values();

            if ($names->isNotEmpty()) {
                return $names;
            }
        }

        return collect();
    }

    /**
     * @return array<int, array{name: string, value: string}>
     */
    private static function getSelectedOptions(Product $product, ProductVariant $variant): array
    {
        $fromColumns = self::optionsFromVariantColumns($variant);

        if ($fromColumns !== []) {
            return $fromColumns;
        }

        $options = [];

        foreach (data_get($variant->raw_payload, 'selectedOptions', []) as $option) {
            if (! is_array($option)) {
                continue;
            }

            $name = (string) ($option['name'] ?? '');
            $value = $option['value'] ?? null;

            if (filled($name) && filled($value)) {
                $options[] = [
                    'name' => $name,
                    'value' => (string) $value,
                ];
            }
        }

        if ($options !== []) {
            return $options;
        }

        foreach (self::productOptions($product) as $index => $option) {
            $name = (string) ($option['name'] ?? '');
            $optionKey = 'option'.($index + 1);
            $value = $variant->{$optionKey} ?? null;

            if (filled($name) && filled($value)) {
                $options[] = [
                    'name' => $name,
                    'value' => (string) $value,
                ];
            }
        }

        return $options;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function productOptions(Product $product): array
    {
        $options = data_get($product->raw_payload, 'options', []);

        return is_array($options) ? $options : [];
    }

    /**
     * @return Collection<int, string>
     */
    private static function optionNames(Product $product): Collection
    {
        return collect(self::productOptions($product))
            ->filter(fn ($option) => is_array($option))
            ->pluck('name')
            ->filter()
            ->map(fn ($name) => (string) $name)
            ->values();
    }
}
