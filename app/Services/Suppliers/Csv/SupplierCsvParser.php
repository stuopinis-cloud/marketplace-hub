<?php

namespace App\Services\Suppliers\Csv;

use App\Models\Supplier;
use Throwable;

class SupplierCsvParser
{
    public const string DEFAULT_DELIMITER = ',';

    public const string DEFAULT_ENCLOSURE = '"';

    public const string DEFAULT_ESCAPE = '\\';

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
     *     preview_rows: array<int, array<string, mixed>>,
     *     detected_delimiter: string
     * }
     */
    public function parse(string $content, Supplier $supplier, ?int $maxRows = null): array
    {
        try {
            return $this->parseInternal($content, $supplier, $maxRows);
        } catch (SupplierCsvParseException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new SupplierCsvParseException(
                'CSV parse failed: '.$exception->getMessage(),
                0,
                $exception,
            );
        }
    }

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
     *     preview_rows: array<int, array<string, mixed>>,
     *     detected_delimiter: string
     * }
     */
    private function parseInternal(string $content, Supplier $supplier, ?int $maxRows = null): array
    {
        $skuColumn = SupplierCsvConfig::skuColumn($supplier);
        $previewOnly = $skuColumn === null;

        $content = $this->normalizeEncoding($content, SupplierCsvConfig::encoding($supplier));
        $lines = $this->splitLines($content);

        if ($lines === []) {
            throw new SupplierCsvParseException('CSV feed is empty.');
        }

        $detectedDelimiter = $this->detectDelimiter($lines);
        $delimiter = $this->normalizeDelimiter(
            $this->configuredDelimiterValue($supplier),
            $detectedDelimiter,
        );
        $enclosure = $this->normalizeEnclosure(SupplierCsvConfig::enclosure($supplier));
        $escape = $this->normalizeEscape(SupplierCsvConfig::escape($supplier));
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

            if ($previewOnly) {
                continue;
            }

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

        if (! $previewOnly && $entries === [] && $skipped === [] && $previewRows === []) {
            throw new SupplierCsvParseException('CSV feed did not contain any data rows.');
        }

        if ($previewOnly && $previewRows === [] && $headers === []) {
            throw new SupplierCsvParseException('CSV feed did not contain any data rows.');
        }

        return [
            'entries' => $entries,
            'skipped' => $skipped,
            'headers' => $headers,
            'preview_rows' => $previewRows,
            'detected_delimiter' => $delimiter,
        ];
    }

    public function normalizeDelimiter(?string $value, string $detected): string
    {
        $detected = $this->isSingleCharacter($detected) ? $detected : self::DEFAULT_DELIMITER;

        if ($value === null) {
            return $detected;
        }

        $trimmed = trim($value);

        if ($trimmed === '' || strcasecmp($trimmed, 'auto') === 0) {
            return $detected;
        }

        $mapped = match (strtolower($trimmed)) {
            'comma' => ',',
            'semicolon' => ';',
            'tab' => "\t",
            'pipe' => '|',
            default => $trimmed,
        };

        return $this->isSingleCharacter($mapped) ? $mapped : $detected;
    }

    public function normalizeEnclosure(?string $value): string
    {
        if ($value === null) {
            return self::DEFAULT_ENCLOSURE;
        }

        $trimmed = trim($value);

        if ($trimmed === '' || strcasecmp($trimmed, 'auto') === 0 || ! $this->isSingleCharacter($trimmed)) {
            return self::DEFAULT_ENCLOSURE;
        }

        return $trimmed;
    }

    public function normalizeEscape(?string $value): string
    {
        if ($value === null) {
            return self::DEFAULT_ESCAPE;
        }

        $trimmed = trim($value);

        if ($trimmed === '' || strcasecmp($trimmed, 'auto') === 0 || ! $this->isSingleCharacter($trimmed)) {
            return self::DEFAULT_ESCAPE;
        }

        return $trimmed;
    }

    /**
     * @param  array<int, string>  $lines
     */
    public function detectDelimiter(array $lines): string
    {
        $sample = collect($lines)
            ->filter(fn (string $line): bool => trim($line) !== '')
            ->take(5)
            ->implode("\n");

        $candidates = [
            ';' => substr_count($sample, ';'),
            ',' => substr_count($sample, ','),
            "\t" => substr_count($sample, "\t"),
            '|' => substr_count($sample, '|'),
        ];

        arsort($candidates);
        $best = array_key_first($candidates);

        return ($best !== null && ($candidates[$best] ?? 0) > 0) ? $best : self::DEFAULT_DELIMITER;
    }

    private function configuredDelimiterValue(Supplier $supplier): ?string
    {
        $value = SupplierCsvConfig::get($supplier, 'csv_delimiter');

        if ($value === null) {
            return null;
        }

        return (string) $value;
    }

    private function isSingleCharacter(string $value): bool
    {
        return $value !== '' && mb_strlen($value) === 1;
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
        $encoding = strtoupper(str_replace('_', '-', $encoding === 'UTF8' ? 'UTF-8' : $encoding));

        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
            $encoding = 'UTF-8';
        }

        if ($encoding === 'AUTO') {
            if (mb_check_encoding($content, 'UTF-8')) {
                return $content;
            }

            foreach (['Windows-1252', 'ISO-8859-1', 'Windows-1257', 'ISO-8859-13'] as $candidate) {
                $converted = @mb_convert_encoding($content, 'UTF-8', $candidate);

                if (is_string($converted) && $converted !== '' && mb_check_encoding($converted, 'UTF-8')) {
                    return $converted;
                }
            }

            return $content;
        }

        if ($encoding === 'UTF-8') {
            return $content;
        }

        $converted = @mb_convert_encoding($content, 'UTF-8', $encoding);

        return is_string($converted) && $converted !== '' ? $converted : $content;
    }

    /**
     * @return array<int, string>
     */
    private function splitLines(string $content): array
    {
        return preg_split("/\R/u", $content) ?: [];
    }
}
