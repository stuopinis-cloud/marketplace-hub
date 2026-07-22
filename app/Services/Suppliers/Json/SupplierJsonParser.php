<?php

namespace App\Services\Suppliers\Json;

use App\Models\Supplier;
use App\Services\Suppliers\SupplierAvailabilityEvaluator;
use RuntimeException;

class SupplierJsonParser
{
    public function __construct(
        private readonly SupplierAvailabilityEvaluator $availabilityEvaluator = new SupplierAvailabilityEvaluator,
    ) {}

    /**
     * @return array{
     *     entries: array<int, array{
     *         sku: string,
     *         stock_quantity: ?int,
     *         availability_status: string,
     *         raw_payload: array<string, mixed>,
     *         parse_issue_code?: string
     *     }>,
     *     skipped: array<int, array<string, mixed>>,
     *     top_level_keys: array<int, string>,
     *     sample_rows: array<int, array<string, mixed>>
     * }
     */
    public function parse(string $json, Supplier $supplier): array
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('JSON feed response is malformed.');
        }

        $dataPath = SupplierJsonConfig::dataPath($supplier);

        if ($dataPath === null) {
            throw new RuntimeException('JSON data path mapping is required.');
        }

        $rows = data_get($decoded, $dataPath);

        if (! is_array($rows)) {
            throw new RuntimeException('JSON feed did not contain an array at data path: '.$dataPath);
        }

        // Associative single object → wrap
        if ($rows !== [] && array_keys($rows) !== range(0, count($rows) - 1) && $this->looksLikeRow($rows)) {
            $rows = [$rows];
        }

        $skuPath = SupplierJsonConfig::skuPath($supplier) ?? 'SKU';
        $stockPath = SupplierJsonConfig::stockPath($supplier);
        $availabilityPath = SupplierJsonConfig::availabilityPath($supplier);
        $barcodePath = SupplierJsonConfig::barcodePath($supplier);
        $titlePath = SupplierJsonConfig::titlePath($supplier);

        $entries = [];
        $skipped = [];
        $sampleRows = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                $skipped[] = [
                    'sku' => '—',
                    'issue_code' => 'invalid_row',
                    'message' => 'Feed row was not an object at index '.$index.'.',
                ];

                continue;
            }

            if (count($sampleRows) < 10) {
                $sampleRows[] = $row;
            }

            $sku = $this->normalizeWhitespace((string) (data_get($row, $skuPath) ?? ''));

            if ($sku === null || $sku === '') {
                $skipped[] = [
                    'sku' => '—',
                    'issue_code' => 'missing_sku',
                    'message' => 'Missing SKU on JSON row '.((int) $index + 1).'.',
                    'raw_payload' => $row,
                ];

                continue;
            }

            $stockRaw = $stockPath !== null ? data_get($row, $stockPath) : null;
            $availabilityRaw = $availabilityPath !== null ? data_get($row, $availabilityPath) : null;
            $parseIssueCode = null;

            if (($stockRaw === null || $stockRaw === '') && ($availabilityRaw === null || $availabilityRaw === '')) {
                $parseIssueCode = 'missing_quantity';
            }

            [$quantity, $availabilityStatus] = $this->resolveStock(
                $stockRaw !== null && $stockRaw !== '' ? (string) $stockRaw : null,
                $availabilityRaw !== null && $availabilityRaw !== '' ? (string) $availabilityRaw : null,
            );

            $barcode = $barcodePath !== null ? $this->normalizeWhitespace((string) (data_get($row, $barcodePath) ?? '')) : null;
            $title = $titlePath !== null ? $this->normalizeWhitespace((string) (data_get($row, $titlePath) ?? '')) : null;

            $entry = [
                'sku' => $sku,
                'stock_quantity' => $quantity,
                'availability_status' => $availabilityStatus,
                'raw_payload' => array_filter([
                    'sku' => $sku,
                    'stock' => $stockRaw,
                    'availability' => $availabilityRaw,
                    'barcode' => $barcode,
                    'title' => $title,
                    'row' => $row,
                ], fn (mixed $value): bool => $value !== null && $value !== ''),
            ];

            if ($parseIssueCode !== null) {
                $entry['parse_issue_code'] = $parseIssueCode;
            }

            $entries[] = $entry;
        }

        return [
            'entries' => $entries,
            'skipped' => $skipped,
            'top_level_keys' => array_values(array_map('strval', array_keys($decoded))),
            'sample_rows' => $sampleRows,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function looksLikeRow(array $row): bool
    {
        foreach ($row as $value) {
            if (is_array($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{0: ?int, 1: string}
     */
    private function resolveStock(?string $stockRaw, ?string $availabilityRaw): array
    {
        if ($stockRaw !== null && $stockRaw !== '' && is_numeric($stockRaw)) {
            $quantity = max(0, (int) $stockRaw);

            return [$quantity, $quantity > 0 ? 'available' : 'unavailable'];
        }

        if ($this->availabilityEvaluator->isTruthy($availabilityRaw)) {
            return [null, 'available'];
        }

        return [0, 'unavailable'];
    }

    private function normalizeWhitespace(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        return $normalized === '' ? null : $normalized;
    }
}
