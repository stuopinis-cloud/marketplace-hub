<?php

namespace App\Services\Suppliers\Helik;

use RuntimeException;

class HelikResponseParser
{
    /**
     * @return array{
     *     entries: array<int, array{
     *         sku: string,
     *         stock_quantity: int,
     *         availability_status: string,
     *         raw_payload: array<string, mixed>,
     *         parse_issue_code?: string
     *     }>,
     *     skipped: array<int, array<string, mixed>>
     * }
     */
    public function parse(string $json, string $dataPath = 'Value'): array
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Helikon feed response is malformed JSON.');
        }

        $rows = data_get($decoded, $dataPath);

        if (! is_array($rows)) {
            throw new RuntimeException('Helikon feed response did not contain the expected data path.');
        }

        $entries = [];
        $skipped = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                $skipped[] = [
                    'issue_code' => 'invalid_row',
                    'message' => 'Feed row was not an object.',
                    'sku' => '—',
                    'raw_payload' => $row,
                ];

                continue;
            }

            $sku = $this->normalizeWhitespace($row['SKU'] ?? $row['sku'] ?? null);

            if ($sku === null || $sku === '') {
                $skipped[] = [
                    'issue_code' => 'missing_sku',
                    'message' => 'Feed row is missing SKU.',
                    'sku' => '—',
                    'raw_payload' => $row,
                ];

                continue;
            }

            $quantityRaw = $row['Quantity'] ?? $row['quantity'] ?? null;
            $parseIssueCode = null;

            if ($quantityRaw === null || $quantityRaw === '') {
                $quantity = 0;
                $parseIssueCode = 'missing_quantity';
            } else {
                $quantity = max(0, (int) $quantityRaw);
            }

            $entries[] = [
                'sku' => $sku,
                'stock_quantity' => $quantity,
                'availability_status' => $quantity > 0 ? 'available' : 'unavailable',
                'raw_payload' => $row,
                'parse_issue_code' => $parseIssueCode,
            ];
        }

        return [
            'entries' => $entries,
            'skipped' => $skipped,
        ];
    }

    public function evaluateQuantity(?int $quantity): string
    {
        return ($quantity ?? 0) > 0 ? 'available' : 'unavailable';
    }

    private function normalizeWhitespace(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim((string) $value));

        return $normalized === '' ? null : $normalized;
    }
}
