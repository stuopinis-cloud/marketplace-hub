<?php

namespace App\Services\Suppliers\Xml;

use App\Models\Supplier;
use App\Services\Suppliers\Csv\SupplierCsvConfig;

class SupplierXmlConfig
{
    public static function isConfigured(Supplier $supplier): bool
    {
        return filled(self::itemPath($supplier)) && filled(self::skuPath($supplier));
    }

    public static function itemPath(Supplier $supplier): ?string
    {
        return self::filledString($supplier, 'xml_item_path');
    }

    public static function skuPath(Supplier $supplier): ?string
    {
        return self::filledString($supplier, 'xml_sku_path');
    }

    public static function stockPath(Supplier $supplier): ?string
    {
        return self::filledString($supplier, 'xml_stock_path');
    }

    public static function availabilityPath(Supplier $supplier): ?string
    {
        return self::filledString($supplier, 'xml_availability_path');
    }

    public static function barcodePath(Supplier $supplier): ?string
    {
        return self::filledString($supplier, 'xml_barcode_path');
    }

    public static function titlePath(Supplier $supplier): ?string
    {
        return self::filledString($supplier, 'xml_title_path');
    }

    /**
     * @return array<string, string>
     */
    public static function namespaces(Supplier $supplier): array
    {
        $namespaces = SupplierCsvConfig::get($supplier, 'xml_namespaces', []);

        if (! is_array($namespaces)) {
            return [];
        }

        $normalized = [];

        foreach ($namespaces as $prefix => $uri) {
            $prefix = trim((string) $prefix);
            $uri = trim((string) $uri);

            if ($prefix === '' || $uri === '') {
                continue;
            }

            $normalized[$prefix] = $uri;
        }

        return $normalized;
    }

    /**
     * @return array<int, string>
     */
    public static function vendorScope(Supplier $supplier): array
    {
        return SupplierCsvConfig::vendorScope($supplier);
    }

    public static function matchingStrategy(Supplier $supplier): string
    {
        return SupplierCsvConfig::matchingStrategy($supplier);
    }

    private static function filledString(Supplier $supplier, string $key): ?string
    {
        $value = SupplierCsvConfig::get($supplier, $key);

        return filled($value) ? trim((string) $value) : null;
    }
}
