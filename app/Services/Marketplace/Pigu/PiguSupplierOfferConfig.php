<?php

namespace App\Services\Marketplace\Pigu;

use App\Models\Supplier;

/**
 * Reads Pigu offers-specific settings from Supplier.config JSON.
 * Defaults match the admin wizard: RRP + VAT, 240h collection, supplier-only export.
 */
class PiguSupplierOfferConfig
{
    public const string CONFIG_KEY = 'pigu_offers';

    public static function isEnabled(Supplier $supplier): bool
    {
        return (bool) data_get($supplier->config, self::CONFIG_KEY.'.enabled', false);
    }

    public static function exportSupplierOnly(Supplier $supplier): bool
    {
        return (bool) data_get($supplier->config, self::CONFIG_KEY.'.export_supplier_only', true);
    }

    public static function collectionHours(Supplier $supplier): int
    {
        return max(1, (int) data_get($supplier->config, self::CONFIG_KEY.'.collection_hours', 240));
    }

    public static function priceIncludesVat(Supplier $supplier): bool
    {
        return (bool) data_get($supplier->config, self::CONFIG_KEY.'.price_includes_vat', false);
    }

    public static function vatRate(Supplier $supplier): float
    {
        return max(0.0, (float) data_get($supplier->config, self::CONFIG_KEY.'.vat_rate', 21));
    }

    public static function priceMultiplier(Supplier $supplier): float
    {
        $multiplier = (float) data_get($supplier->config, self::CONFIG_KEY.'.price_multiplier', 1);

        return $multiplier > 0 ? $multiplier : 1.0;
    }

    public static function priceRounding(Supplier $supplier): ?string
    {
        $rounding = data_get($supplier->config, self::CONFIG_KEY.'.price_rounding');

        return filled($rounding) ? (string) $rounding : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'export_supplier_only' => true,
            'collection_hours' => 240,
            'price_source' => 'recommended_retail',
            'price_includes_vat' => false,
            'vat_rate' => 21,
            'price_multiplier' => 1,
            'price_rounding' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        $defaults = self::defaults();

        return [
            'enabled' => (bool) ($input['enabled'] ?? $defaults['enabled']),
            'export_supplier_only' => (bool) ($input['export_supplier_only'] ?? $defaults['export_supplier_only']),
            'collection_hours' => max(1, (int) ($input['collection_hours'] ?? $defaults['collection_hours'])),
            'price_source' => (string) ($input['price_source'] ?? $defaults['price_source']),
            'price_includes_vat' => (bool) ($input['price_includes_vat'] ?? $defaults['price_includes_vat']),
            'vat_rate' => max(0.0, (float) ($input['vat_rate'] ?? $defaults['vat_rate'])),
            'price_multiplier' => max(0.0001, (float) ($input['price_multiplier'] ?? $defaults['price_multiplier'])),
            'price_rounding' => filled($input['price_rounding'] ?? null)
                ? (string) $input['price_rounding']
                : null,
        ];
    }
}
