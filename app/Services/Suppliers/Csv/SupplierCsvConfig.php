<?php

namespace App\Services\Suppliers\Csv;

use App\Models\Supplier;

class SupplierCsvConfig
{
    public static function delimiterChar(Supplier $supplier): string
    {
        return match (self::get($supplier, 'csv_delimiter', 'comma')) {
            'semicolon' => ';',
            'tab' => "\t",
            'pipe' => '|',
            default => ',',
        };
    }

    public static function enclosure(Supplier $supplier): string
    {
        return (string) self::get($supplier, 'csv_enclosure', '"');
    }

    public static function escape(Supplier $supplier): string
    {
        return (string) self::get($supplier, 'csv_escape', '\\');
    }

    public static function hasHeader(Supplier $supplier): bool
    {
        return (bool) self::get($supplier, 'csv_has_header', true);
    }

    public static function encoding(Supplier $supplier): string
    {
        return (string) self::get($supplier, 'csv_encoding', 'UTF-8');
    }

    public static function dataStartRow(Supplier $supplier): int
    {
        return max(1, (int) self::get($supplier, 'csv_data_start_row', 1));
    }

    public static function skuColumn(Supplier $supplier): ?string
    {
        $column = self::get($supplier, 'csv_sku_column');

        return filled($column) ? (string) $column : null;
    }

    public static function stockColumn(Supplier $supplier): ?string
    {
        $column = self::get($supplier, 'csv_stock_column');

        return filled($column) ? (string) $column : null;
    }

    public static function availabilityColumn(Supplier $supplier): ?string
    {
        $column = self::get($supplier, 'csv_availability_column');

        return filled($column) ? (string) $column : null;
    }

    public static function barcodeColumn(Supplier $supplier): ?string
    {
        $column = self::get($supplier, 'csv_barcode_column');

        return filled($column) ? (string) $column : null;
    }

    public static function vendorColumn(Supplier $supplier): ?string
    {
        $column = self::get($supplier, 'csv_vendor_column');

        return filled($column) ? (string) $column : null;
    }

    public static function titleColumn(Supplier $supplier): ?string
    {
        $column = self::get($supplier, 'csv_title_column');

        return filled($column) ? (string) $column : null;
    }

    public static function priceColumn(Supplier $supplier): ?string
    {
        $column = self::get($supplier, 'csv_price_column');

        return filled($column) ? (string) $column : null;
    }

    public static function uploadedFilePath(Supplier $supplier): ?string
    {
        $path = self::get($supplier, 'uploaded_file_path');

        return filled($path) ? (string) $path : null;
    }

    /**
     * @return array<int, string>
     */
    public static function vendorScope(Supplier $supplier): array
    {
        $scope = self::get($supplier, 'vendor_scope', []);

        if (! is_array($scope)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $vendor): string => trim((string) $vendor),
            $scope,
        )));
    }

    /**
     * @return array<int, string>
     */
    public static function truthyValues(Supplier $supplier): array
    {
        $custom = self::get($supplier, 'availability_truthy_values');

        if (is_array($custom) && $custom !== []) {
            return array_values(array_map(fn (mixed $value): string => mb_strtolower(trim((string) $value)), $custom));
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    public static function falsyValues(Supplier $supplier): array
    {
        $custom = self::get($supplier, 'availability_falsy_values');

        if (is_array($custom) && $custom !== []) {
            return array_values(array_map(fn (mixed $value): string => mb_strtolower(trim((string) $value)), $custom));
        }

        return [];
    }

    public static function get(Supplier $supplier, string $key, mixed $default = null): mixed
    {
        return data_get($supplier->config, $key, $default);
    }
}
