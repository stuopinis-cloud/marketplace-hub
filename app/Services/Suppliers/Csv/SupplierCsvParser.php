<?php

namespace App\Services\Suppliers\Csv;

use App\Models\Supplier;
use RuntimeException;

class SupplierCsvParser
{
    public function __construct(
        private readonly SupplierCsvStockResolver $stockResolver = new SupplierCsvStockResolver,
    ) {}

    /**
     * @return array{
     *     entries: array<int, array{
     *         sku: string,
     *         stock_quantity: ?int,
     *         availability_status: string,
     *         raw_payload: array<string, mixed>
     *     }>,
     *     skipped: array<int, array<string, mixed>>,
     *     headers: array<int, string>,
     *     preview_rows: array<int, array<string, mixed>>
     * }
     */
    public function parse(string $content, Supplier $supplier, ?int $maxRows = null): array
    {
        $skuColumn = SupplierCsvConfig::skuColumn($supplier);

        if ($skuColumn === null) {
            throw new RuntimeException('CSV SKU column mapping is required.');
        }

        $content = $this->normalizeEncoding($content, SupplierCsvConfig::encoding($supplier));
        $lines = $this->splitLines($content);

        if ($lines === []) {
            throw new RuntimeException('CSV feed is empty.');
        }

        $delimiter = SupplierCsvConfig::delimiterChar($supplier);
        $enclosure = SupplierCsvConfig::enclosure($supplier);
        $escape = SupplierCsvConfig::escape($supplier);
        $hasHeader = SupplierCsvConfig::hasHeader($supplier);
        $dataStartRow = SupplierCsvConfig::dataStartRow($supplier);

        $headers = [];
        $entries = [];
        $skipped = [];
        $previewRows = [];
        $rowNumber = 0;
        $parsedDataRows = 0;

        foreach ($lines as $line) {
            $rowNumber++;

            if (trim($line) === '') {
                continue;
            }

            $columns = str_getcsv($line, $delimiter, $enclosure, $escape);

            if ($columns === false) {
                continue;
            }

            $columns = array_map(fn (mixed $value): string => trim((string) $value), $columns);

            if ($rowNumber < $dataStartRow) {
                continue;
            }

            if ($hasHeader && $headers === []) {
                $headers = $columns;

                continue;
            }

            if (! $hasHeader && $headers === []) {
                $headers = $this->buildPositionalHeaders(count($columns));
            }

            $row = $this->mapRow($headers, $columns);

            if (count($previewRows) < ($maxRows ?? PHP_INT_MAX)) {
                $previewRows[] = $row;
            }

            if ($maxRows !== null && $parsedDataRows >= $maxRows) {
                continue;
            }

            $parsedDataRows++;
            $sku = trim((string) ($row[$skuColumn] ?? ''));

            if ($sku === '') {
                $skipped[] = [
                    'sku' => '—',
                    'issue_code' => 'missing_sku',
                    'message' => 'Missing SKU on CSV row '.$rowNumber.'.',
                    'row' => $row,
                ];

                continue;
            }

            $stockRaw = $this->columnValue($row, SupplierCsvConfig::stockColumn($supplier));
            $availabilityRaw = $this->columnValue($row, SupplierCsvConfig::availabilityColumn($supplier));
            [$quantity, $availabilityStatus] = $this->stockResolver->resolve($stockRaw, $availabilityRaw, $supplier);

            $entries[] = [
                'sku' => $sku,
                'stock_quantity' => $quantity,
                'availability_status' => $availabilityStatus,
                'raw_payload' => array_filter([
                    'sku' => $sku,
                    'stock' => $stockRaw,
                    'availability' => $availabilityRaw,
                    'barcode' => $this->columnValue($row, SupplierCsvConfig::barcodeColumn($supplier)),
                    'vendor' => $this->columnValue($row, SupplierCsvConfig::vendorColumn($supplier)),
                    'title' => $this->columnValue($row, SupplierCsvConfig::titleColumn($supplier)),
                    'price' => $this->columnValue($row, SupplierCsvConfig::priceColumn($supplier)),
                    'row' => $row,
                ], fn (mixed $value): bool => $value !== null && $value !== ''),
            ];
        }

        if ($entries === [] && $skipped === [] && $previewRows === []) {
            throw new RuntimeException('CSV feed did not contain any data rows.');
        }

        return [
            'entries' => $entries,
            'skipped' => $skipped,
            'headers' => $headers,
            'preview_rows' => $previewRows,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function buildPositionalHeaders(int $count): array
    {
        $headers = [];

        for ($index = 0; $index < $count; $index++) {
            $headers[] = 'column_'.($index + 1);
        }

        return $headers;
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, string>  $columns
     * @return array<string, string>
     */
    private function mapRow(array $headers, array $columns): array
    {
        $row = [];

        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }

            $row[$header] = $columns[$index] ?? '';
        }

        return $row;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function columnValue(array $row, ?string $column): ?string
    {
        if ($column === null) {
            return null;
        }

        $value = trim((string) ($row[$column] ?? ''));

        return $value === '' ? null : $value;
    }

    private function normalizeEncoding(string $content, string $encoding): string
    {
        $encoding = strtoupper($encoding === 'UTF8' ? 'UTF-8' : $encoding);

        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        if ($encoding === 'UTF-8') {
            return $content;
        }

        $converted = mb_convert_encoding($content, 'UTF-8', $encoding);

        return $converted === false ? $content : $converted;
    }

    /**
     * @return array<int, string>
     */
    private function splitLines(string $content): array
    {
        return preg_split("/\R/u", $content) ?: [];
    }
}
