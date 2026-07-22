<?php

namespace App\Services\Suppliers\Json;

use App\Models\Supplier;
use App\Services\Suppliers\Csv\SupplierCsvConfig;

class SupplierJsonConfig
{
    public static function isConfigured(Supplier $supplier): bool
    {
        return filled(self::dataPath($supplier)) && filled(self::skuPath($supplier));
    }

    public static function dataPath(Supplier $supplier): ?string
    {
        return self::filledString($supplier, 'json_data_path')
            ?? self::filledString($supplier, 'response_data_path');
    }

    public static function skuPath(Supplier $supplier): ?string
    {
        return self::filledString($supplier, 'json_sku_path') ?? 'SKU';
    }

    public static function stockPath(Supplier $supplier): ?string
    {
        return self::filledString($supplier, 'json_stock_path') ?? 'Quantity';
    }

    public static function availabilityPath(Supplier $supplier): ?string
    {
        return self::filledString($supplier, 'json_availability_path');
    }

    public static function barcodePath(Supplier $supplier): ?string
    {
        return self::filledString($supplier, 'json_barcode_path');
    }

    public static function titlePath(Supplier $supplier): ?string
    {
        return self::filledString($supplier, 'json_title_path');
    }

    public static function method(Supplier $supplier): string
    {
        $method = strtoupper((string) SupplierCsvConfig::get($supplier, 'method', 'GET'));

        return in_array($method, ['GET', 'POST'], true) ? $method : 'GET';
    }

    /**
     * @return array<string, mixed>|list<mixed>|null
     */
    public static function requestBody(Supplier $supplier): array|null
    {
        $body = SupplierCsvConfig::get($supplier, 'request_body');

        return is_array($body) ? $body : null;
    }

    /**
     * @return array<int, string>
     */
    public static function vendorScope(Supplier $supplier): array
    {
        return SupplierCsvConfig::vendorScope($supplier);
    }

    private static function filledString(Supplier $supplier, string $key): ?string
    {
        $value = SupplierCsvConfig::get($supplier, $key);

        return filled($value) ? trim((string) $value) : null;
    }
}
