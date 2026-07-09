<?php

namespace App\Services\Marketplace;

use App\Models\CategoryMapping;
use Illuminate\Support\Facades\Storage;

class CategoryMappingCsvImporter
{
    public const string FAILED_CSV_DIRECTORY = 'exports/category-mapping-imports';

    /**
     * @var list<string>
     */
    public const array REQUIRED_HEADERS = [
        'shopify_collection',
        'shopify_handle',
        'varle_final_category',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parse(string $absolutePath): array
    {
        $handle = fopen($absolutePath, 'r');

        if ($handle === false) {
            throw new \RuntimeException('Unable to open CSV file for reading.');
        }

        try {
            $headerRow = fgetcsv($handle);

            if ($headerRow === false) {
                throw new \InvalidArgumentException('CSV file is empty.');
            }

            $headerRow[0] = $this->stripBom((string) ($headerRow[0] ?? ''));
            $headerMap = $this->normalizeHeaders($headerRow);
            $this->assertRequiredHeaders($headerMap);

            $rows = [];
            $lineNumber = 1;

            while (($csvRow = fgetcsv($handle)) !== false) {
                $lineNumber++;

                if ($this->isBlankRow($csvRow)) {
                    continue;
                }

                $assoc = $this->associateRow($headerMap, $csvRow);

                $rows[] = [
                    'line' => $lineNumber,
                    'shopify_collection' => $this->normalizeCell($assoc['shopify_collection'] ?? ''),
                    'shopify_handle' => $this->normalizeCell($assoc['shopify_handle'] ?? ''),
                    'varle_final_category' => $this->normalizeCell($assoc['varle_final_category'] ?? ''),
                ];
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function plan(array $rows, CategoryMappingCsvImportOptions $options): CategoryMappingCsvImportResult
    {
        $plannedRows = [];
        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $skipped = 0;
        $failed = 0;
        $failedRows = [];

        foreach ($rows as $row) {
            $planned = $this->planRow($row, $options);
            $plannedRows[] = $planned;

            match ($planned['action']) {
                'create' => $created++,
                'update' => $updated++,
                'unchanged' => $unchanged++,
                'skip' => $skipped++,
                'error' => $failed++,
                default => null,
            };

            if (in_array($planned['action'], ['skip', 'error'], true)) {
                $failedRows[] = $planned;
            }
        }

        return new CategoryMappingCsvImportResult(
            totalRows: count($rows),
            created: $created,
            updated: $updated,
            unchanged: $unchanged,
            skipped: $skipped,
            failed: $failed,
            dryRun: $options->dryRun,
            previewRows: array_slice($plannedRows, 0, 20),
            failedRows: $failedRows,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function import(string $absolutePath, CategoryMappingCsvImportOptions $options): CategoryMappingCsvImportResult
    {
        $rows = $this->parse($absolutePath);
        $plan = $this->plan($rows, $options);

        if ($options->dryRun) {
            return $plan;
        }

        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $skipped = 0;
        $failed = 0;
        $failedRows = [];

        foreach ($rows as $row) {
            $planned = $this->planRow($row, $options);

            if ($planned['action'] === 'create' || $planned['action'] === 'update') {
                CategoryMapping::query()->updateOrCreate(
                    [
                        'marketplace_channel_id' => $options->marketplaceChannelId,
                        'source_type' => $options->sourceType,
                        'source_value' => $planned['source_value'],
                    ],
                    [
                        'target_category_path' => $planned['target_category_path'],
                        'priority' => $options->priority,
                        'enabled' => $options->enabled,
                        'export_enabled' => $options->exportEnabled,
                    ],
                );

                if ($planned['action'] === 'create') {
                    $created++;
                } else {
                    $updated++;
                }

                continue;
            }

            if ($planned['action'] === 'unchanged') {
                $unchanged++;

                continue;
            }

            if ($planned['action'] === 'skip') {
                $skipped++;
                $failedRows[] = $planned;

                continue;
            }

            $failed++;
            $failedRows[] = $planned;
        }

        $result = new CategoryMappingCsvImportResult(
            totalRows: count($rows),
            created: $created,
            updated: $updated,
            unchanged: $unchanged,
            skipped: $skipped,
            failed: $failed,
            dryRun: false,
            previewRows: array_slice(array_map(fn (array $row): array => $this->planRow($row, $options), $rows), 0, 20),
            failedRows: $failedRows,
        );

        if ($failedRows !== []) {
            $result->failedCsvRelativePath = $this->writeFailedRowsCsv($failedRows);
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $failedRows
     */
    public function writeFailedRowsCsv(array $failedRows): string
    {
        Storage::disk('public')->makeDirectory(self::FAILED_CSV_DIRECTORY);

        $relativePath = self::FAILED_CSV_DIRECTORY.'/failed_'.now()->format('Ymd_His').'_'.uniqid().'.csv';
        $absolutePath = Storage::disk('public')->path($relativePath);
        $handle = fopen($absolutePath, 'w');

        if ($handle === false) {
            throw new \RuntimeException('Unable to open failed rows CSV for writing.');
        }

        try {
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'line',
                'shopify_collection',
                'shopify_handle',
                'varle_final_category',
                'action',
                'message',
            ]);

            foreach ($failedRows as $row) {
                fputcsv($handle, [
                    $row['line'] ?? '',
                    $row['shopify_collection'] ?? '',
                    $row['shopify_handle'] ?? '',
                    $row['varle_final_category'] ?? '',
                    $row['action'] ?? '',
                    $row['message'] ?? '',
                ]);
            }
        } finally {
            fclose($handle);
        }

        return $relativePath;
    }

    public function previewFromStoragePath(string $storagePath, CategoryMappingCsvImportOptions $options, string $disk = 'local'): CategoryMappingCsvImportResult
    {
        $absolutePath = Storage::disk($disk)->path($storagePath);

        return $this->plan($this->parse($absolutePath), $options);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function planRow(array $row, CategoryMappingCsvImportOptions $options): array
    {
        $handle = $this->normalizeCell((string) ($row['shopify_handle'] ?? ''));
        $category = $this->normalizeCell((string) ($row['varle_final_category'] ?? ''));

        $planned = [
            'line' => (int) ($row['line'] ?? 0),
            'shopify_collection' => $this->normalizeCell((string) ($row['shopify_collection'] ?? '')),
            'shopify_handle' => $handle,
            'varle_final_category' => $category,
            'source_value' => $handle,
            'target_category_path' => $category,
            'action' => 'create',
            'message' => '',
        ];

        if ($handle === '') {
            return $this->markRow($planned, 'skip', 'shopify_handle is empty.');
        }

        if ($category === '') {
            return $this->markRow($planned, 'skip', 'varle_final_category is empty.');
        }

        $existing = CategoryMapping::query()
            ->where('marketplace_channel_id', $options->marketplaceChannelId)
            ->where('source_type', $options->sourceType)
            ->where('source_value', $handle)
            ->first();

        if ($existing === null) {
            return $this->markRow($planned, 'create', 'Will create a new mapping.');
        }

        if ($this->mappingMatches($existing, $planned, $options)) {
            return $this->markRow($planned, 'unchanged', 'Existing mapping already matches.');
        }

        return $this->markRow($planned, 'update', 'Will update the existing mapping.');
    }

    /**
     * @param  array<string, mixed>  $planned
     * @return array<string, mixed>
     */
    private function markRow(array $planned, string $action, string $message): array
    {
        $planned['action'] = $action;
        $planned['message'] = $message;

        return $planned;
    }

    private function mappingMatches(CategoryMapping $existing, array $planned, CategoryMappingCsvImportOptions $options): bool
    {
        return $existing->target_category_path === $planned['target_category_path']
            && (int) $existing->priority === $options->priority
            && (bool) $existing->enabled === $options->enabled
            && (bool) $existing->export_enabled === $options->exportEnabled;
    }

    /**
     * @param  list<string|null>  $headerRow
     * @return array<string, int>
     */
    private function normalizeHeaders(array $headerRow): array
    {
        $map = [];

        foreach ($headerRow as $index => $header) {
            $normalized = strtolower(trim((string) $header));

            if ($normalized === '') {
                continue;
            }

            $map[$normalized] = $index;
        }

        return $map;
    }

    /**
     * @param  array<string, int>  $headerMap
     * @param  list<string|null>  $csvRow
     * @return array<string, string>
     */
    private function associateRow(array $headerMap, array $csvRow): array
    {
        $assoc = [];

        foreach ($headerMap as $header => $index) {
            $assoc[$header] = (string) ($csvRow[$index] ?? '');
        }

        return $assoc;
    }

    /**
     * @param  array<string, int>  $headerMap
     */
    private function assertRequiredHeaders(array $headerMap): void
    {
        $missing = array_values(array_filter(
            self::REQUIRED_HEADERS,
            fn (string $header): bool => ! array_key_exists($header, $headerMap),
        ));

        if ($missing !== []) {
            throw new \InvalidArgumentException('Missing required CSV columns: '.implode(', ', $missing));
        }
    }

    /**
     * @param  list<string|null>  $csvRow
     */
    private function isBlankRow(array $csvRow): bool
    {
        foreach ($csvRow as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function normalizeCell(string $value): string
    {
        $value = trim($value);

        if ($value !== '' && str_starts_with($value, '"') && str_ends_with($value, '"')) {
            $value = trim($value, '"');
        }

        return $value;
    }

    private function stripBom(string $value): string
    {
        if (str_starts_with($value, "\xEF\xBB\xBF")) {
            return substr($value, 3);
        }

        return $value;
    }
}
