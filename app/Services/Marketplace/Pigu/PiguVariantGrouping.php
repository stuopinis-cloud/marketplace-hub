<?php

namespace App\Services\Marketplace\Pigu;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Marketplace\Varle\VarleVariantPresenter;

/**
 * Pigu PHH requires separate <product> entries for colour/lens/material/style variants.
 * Size-like options remain as <modification> rows.
 */
class PiguVariantGrouping
{
    /**
     * @var list<string>
     */
    private const SPLIT_OPTION_NAMES = [
        'color', 'colour', 'spalva', 'spalvos', 'spalvų', 'spalvu',
        'farba', 'farbe', 'couleur',
        'lens', 'lenses', 'lęšis', 'lęšiai', 'lesiai', 'lesis',
        'tint', 'tints', 'atspalvis', 'atspalviai',
        'material', 'medžiaga', 'medziaga', 'fabric',
        'style', 'stilius', 'pattern', 'raštas', 'rastas',
        'finish', 'apdaila',
    ];

    /**
     * @var list<string>
     */
    private const KNOWN_SPLIT_VALUES = [
        'skaidrūs', 'skaidrus', 'skaidri', 'skaidrios',
        'geltoni', 'geltonas', 'geltona', 'geltonos',
        'tamsinti', 'tamsintas', 'tamsinta', 'tamsintos',
        'juoda', 'juodi', 'juodos', 'juodas',
        'žalia', 'zalia', 'žali', 'zali',
        'mėlyna', 'melyna', 'mėlyni', 'melyni',
        'ruda', 'rudi', 'rudos',
        'balta', 'balti', 'baltos',
        'pilka', 'pilki', 'pilkos',
        'raudona', 'raudoni', 'raudonos',
        'smėlio', 'smelio', 'coyote', 'od green', 'multicam',
    ];

    public static function isSplitOptionName(string $name): bool
    {
        $normalized = mb_strtolower(trim($name));

        if (VarleVariantPresenter::isColorOptionName($name)) {
            return true;
        }

        return in_array($normalized, self::SPLIT_OPTION_NAMES, true);
    }

    public static function isKnownSplitValue(?string $value): bool
    {
        if ($value === null || trim($value) === '') {
            return false;
        }

        return in_array(mb_strtolower(trim($value)), self::KNOWN_SPLIT_VALUES, true);
    }

    public static function isModificationOptionName(string $name): bool
    {
        return VarleVariantPresenter::isSizeOptionName($name);
    }

    public static function productHasSplitOption(Product $product): bool
    {
        foreach (VarleVariantPresenter::detectMeaningfulOptions($product) as $option) {
            if (self::isSplitOptionName($option['name'])) {
                return true;
            }

            foreach ($option['values'] as $value) {
                if (self::isKnownSplitValue($value) && ! self::isModificationOptionName($option['name'])) {
                    return true;
                }
            }
        }

        foreach ($product->variants as $variant) {
            if (self::splitValue($product, $variant) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{name: string, value: string}|null
     */
    public static function getSplitOption(Product $product, ProductVariant $variant): ?array
    {
        $options = VarleVariantPresenter::getMeaningfulOptions($product, $variant);

        foreach ($options as $option) {
            if (self::isSplitOptionName($option['name'])) {
                return $option;
            }
        }

        foreach ($options as $option) {
            if (! self::isModificationOptionName($option['name']) && self::isKnownSplitValue($option['value'])) {
                return $option;
            }
        }

        return null;
    }

    public static function splitValue(Product $product, ProductVariant $variant): ?string
    {
        $option = self::getSplitOption($product, $variant);

        return $option !== null ? $option['value'] : null;
    }

    /**
     * Options that stay as modifications (size-like), excluding product-split axes.
     *
     * @return list<array{name: string, value: string}>
     */
    public static function getModificationOptions(Product $product, ProductVariant $variant): array
    {
        return array_values(array_filter(
            VarleVariantPresenter::getMeaningfulOptions($product, $variant),
            function (array $option) use ($product, $variant): bool {
                if (self::isSplitOptionName($option['name'])) {
                    return false;
                }

                $split = self::getSplitOption($product, $variant);
                if ($split !== null
                    && mb_strtolower($split['name']) === mb_strtolower($option['name'])
                    && mb_strtolower($split['value']) === mb_strtolower($option['value'])
                ) {
                    return false;
                }

                if (self::isKnownSplitValue($option['value']) && ! self::isModificationOptionName($option['name'])) {
                    return false;
                }

                return true;
            },
        ));
    }

    public static function modificationTitle(Product $product, ProductVariant $variant): string
    {
        $options = self::getModificationOptions($product, $variant);

        if ($options === []) {
            return '';
        }

        return collect($options)->pluck('value')->implode(' / ');
    }

    /**
     * @param  list<array{variant: ProductVariant, quantity: int}>  $validVariants
     * @return list<array{split_value: ?string, variants: list<array{variant: ProductVariant, quantity: int}>}>
     */
    public static function buildGroups(Product $product, array $validVariants): array
    {
        if (! self::productHasSplitOption($product)) {
            return [['split_value' => null, 'variants' => $validVariants]];
        }

        return collect($validVariants)
            ->filter(fn (array $row): bool => filled(self::splitValue($product, $row['variant'])))
            ->groupBy(fn (array $row): string => (string) self::splitValue($product, $row['variant']))
            ->map(fn ($rows, string $value): array => [
                'split_value' => $value,
                'variants' => $rows->values()->all(),
            ])
            ->values()
            ->all();
    }

    public static function appendSplitValueToTitle(string $title, ?string $splitValue): string
    {
        $title = trim($title);
        $splitValue = $splitValue !== null ? trim($splitValue) : '';

        if ($splitValue === '') {
            return $title;
        }

        if (str_contains(mb_strtolower($title), mb_strtolower($splitValue))) {
            return $title;
        }

        return $title.' - '.$splitValue;
    }
}
