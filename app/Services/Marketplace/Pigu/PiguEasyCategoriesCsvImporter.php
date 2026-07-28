<?php

namespace App\Services\Marketplace\Pigu;

use App\Models\CategoryMapping;
use App\Models\MarketplaceChannel;
use App\Models\PiguDimensionRule;
use App\Models\PiguPropertyRule;
use App\Models\Product;
use RuntimeException;

class PiguEasyCategoriesCsvImporter
{
    /**
     * @return array{
     *     dry_run: bool,
     *     rows_read: int,
     *     rows_importable: int,
     *     rows_skipped: int,
     *     duplicate_handles: list<array{handle: string, losing_line: int, winning_line: int}>,
     *     skipped: list<array{line: int, handle: string, reason: string}>,
     *     category_mappings: array{create: int, update: int},
     *     dimension_rules: array{create: int, update: int},
     *     property_rules: array{create: int, update: int},
     *     planned: list<array<string, mixed>>
     * }
     */
    public function import(string $path, bool $dryRun = false): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("CSV file not found: {$path}");
        }

        $channel = $this->resolvePiguChannel();
        $rows = $this->parseCsv($path);

        $byHandle = [];
        $duplicateHandles = [];
        $skipped = [];

        foreach ($rows as $row) {
            $handle = $row['handle'];

            if ($handle === '') {
                $skipped[] = ['line' => $row['line'], 'handle' => '', 'reason' => 'Missing Shopify handle'];

                continue;
            }

            if (! $row['export_enabled']) {
                $skipped[] = ['line' => $row['line'], 'handle' => $handle, 'reason' => 'export_enabled=false'];

                continue;
            }

            if ($row['contains_nekelti']) {
                $skipped[] = ['line' => $row['line'], 'handle' => $handle, 'reason' => 'Užpildomos reikšmės contains Nekelti'];

                continue;
            }

            if ($row['category_id'] === null) {
                $skipped[] = ['line' => $row['line'], 'handle' => $handle, 'reason' => 'Invalid Pigu category ID'];

                continue;
            }

            if ($row['weight_kg'] === null || $row['length_m'] === null || $row['width_m'] === null || $row['height_m'] === null) {
                $skipped[] = ['line' => $row['line'], 'handle' => $handle, 'reason' => 'Invalid dimensions'];

                continue;
            }

            if (isset($byHandle[$handle])) {
                $duplicateHandles[] = [
                    'handle' => $handle,
                    'losing_line' => $byHandle[$handle]['line'],
                    'winning_line' => $row['line'],
                ];
            }

            $byHandle[$handle] = $row;
        }

        $planned = array_values($byHandle);
        $categoryCreate = 0;
        $categoryUpdate = 0;
        $dimensionCreate = 0;
        $dimensionUpdate = 0;
        $propertyCreate = 0;
        $propertyUpdate = 0;

        foreach ($planned as $row) {
            $mappingResult = $this->upsertCategoryMapping($channel, $row, $dryRun);
            $categoryCreate += $mappingResult === 'create' ? 1 : 0;
            $categoryUpdate += $mappingResult === 'update' ? 1 : 0;

            $dimensionResult = $this->upsertDimensionRule($row, $dryRun);
            $dimensionCreate += $dimensionResult === 'create' ? 1 : 0;
            $dimensionUpdate += $dimensionResult === 'update' ? 1 : 0;

            foreach ($row['properties'] as $property) {
                $propertyResult = $this->upsertPropertyRule($row, $property, $dryRun);
                $propertyCreate += $propertyResult === 'create' ? 1 : 0;
                $propertyUpdate += $propertyResult === 'update' ? 1 : 0;
            }
        }

        return [
            'dry_run' => $dryRun,
            'rows_read' => count($rows),
            'rows_importable' => count($planned),
            'rows_skipped' => count($skipped),
            'duplicate_handles' => $duplicateHandles,
            'skipped' => $skipped,
            'category_mappings' => ['create' => $categoryCreate, 'update' => $categoryUpdate],
            'dimension_rules' => ['create' => $dimensionCreate, 'update' => $dimensionUpdate],
            'property_rules' => ['create' => $propertyCreate, 'update' => $propertyUpdate],
            'planned' => $planned,
        ];
    }

    public function resolvePiguChannel(): MarketplaceChannel
    {
        return MarketplaceChannel::query()->firstOrCreate(
            ['type' => 'pigu'],
            [
                'name' => 'Pigu / PHH',
                'enabled' => true,
                'config' => [
                    'feed_path' => config('marketplace.exports.pigu.feed_path', 'feeds/pigu-products.xml'),
                    'require_category_mapping' => true,
                    'allow_fallback_product_images' => true,
                ],
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Unable to read CSV: {$path}");
        }

        try {
            $headerRow = fgetcsv($handle);

            if ($headerRow === false) {
                throw new RuntimeException('CSV file is empty.');
            }

            $headerRow[0] = $this->stripBom((string) ($headerRow[0] ?? ''));
            $headers = array_map(fn ($value) => trim((string) $value), $headerRow);
            $map = array_flip($headers);

            $required = [
                'Shopify handle',
                'Pigu kategorijų ID',
                'Pigu kategorijos',
                'export_enabled',
                'Preliminarus svoris, kg',
                'Ilgis, m',
                'Plotis, m',
                'Aukštis, m',
            ];

            $normalizedMap = [];
            foreach ($map as $header => $index) {
                $normalizedMap[$this->normalizeHeader($header)] = $index;
            }

            foreach ($required as $header) {
                if (! array_key_exists($this->normalizeHeader($header), $normalizedMap)) {
                    throw new RuntimeException("CSV is missing required column: {$header}");
                }
            }

            $rows = [];
            $line = 1;

            while (($csvRow = fgetcsv($handle)) !== false) {
                $line++;

                if ($this->isBlankRow($csvRow)) {
                    continue;
                }

                $fillValues = $this->cell($csvRow, $normalizedMap, 'Užpildomos reikšmės');
                $categoryIdRaw = $this->cell($csvRow, $normalizedMap, 'Pigu kategorijų ID');
                $categoryName = $this->cell($csvRow, $normalizedMap, 'Pigu kategorijos');
                $handleValue = $this->cell($csvRow, $normalizedMap, 'Shopify handle');

                $rows[] = [
                    'line' => $line,
                    'shopify_category' => $this->cell($csvRow, $normalizedMap, 'Shopify kategorija'),
                    'handle' => $handleValue,
                    'category_id' => $this->parseCategoryId($categoryIdRaw),
                    'category_name' => $categoryName,
                    'fill_values_raw' => $fillValues,
                    'contains_nekelti' => str_contains(mb_strtolower($fillValues), 'nekelti'),
                    'export_enabled' => $this->parseBool($this->cell($csvRow, $normalizedMap, 'export_enabled')),
                    'weight_kg' => $this->parsePositiveDecimal($this->cell($csvRow, $normalizedMap, 'Preliminarus svoris, kg')),
                    'length_m' => $this->parsePositiveDecimal($this->cell($csvRow, $normalizedMap, 'Ilgis, m')),
                    'width_m' => $this->parsePositiveDecimal($this->cell($csvRow, $normalizedMap, 'Plotis, m')),
                    'height_m' => $this->parsePositiveDecimal($this->cell($csvRow, $normalizedMap, 'Aukštis, m')),
                    'properties' => $this->parseFillValues($fillValues),
                ];
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return list<array{property_name: string, value_source: string, value_name: ?string}>
     */
    public function parseFillValues(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $parts = array_map('trim', explode('|', $raw));
        $properties = [];

        foreach ($parts as $part) {
            if ($part === '' || ! str_contains($part, ':')) {
                continue;
            }

            [$name, $value] = array_map('trim', explode(':', $part, 2));

            if ($name === '') {
                continue;
            }

            if ($value === 'Vendor') {
                $properties[] = [
                    'property_name' => $name,
                    'value_source' => PiguPropertyRule::VALUE_SOURCE_VENDOR,
                    'value_name' => null,
                ];
            } else {
                $properties[] = [
                    'property_name' => $name,
                    'value_source' => PiguPropertyRule::VALUE_SOURCE_FIXED,
                    'value_name' => $value,
                ];
            }
        }

        return $properties;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function upsertCategoryMapping(MarketplaceChannel $channel, array $row, bool $dryRun): string
    {
        $existing = CategoryMapping::query()
            ->where('marketplace_channel_id', $channel->id)
            ->where('source_type', 'collection')
            ->where('source_value', $row['handle'])
            ->first();

        $payload = [
            'target_category_id' => (string) $row['category_id'],
            'target_category_name' => $row['category_name'],
            'target_category_path' => $row['category_id'].'|'.$row['category_name'],
            'enabled' => true,
            'export_enabled' => true,
            'priority' => 100,
        ];

        if ($existing === null) {
            if (! $dryRun) {
                CategoryMapping::query()->create([
                    'marketplace_channel_id' => $channel->id,
                    'source_type' => 'collection',
                    'source_value' => $row['handle'],
                    ...$payload,
                ]);
            }

            return 'create';
        }

        if (! $dryRun) {
            $existing->update($payload);
        }

        return 'update';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function upsertDimensionRule(array $row, bool $dryRun): string
    {
        $existing = PiguDimensionRule::query()
            ->where('match_type', 'collection')
            ->where('match_value', $row['handle'])
            ->first();

        $payload = [
            'weight_kg' => $row['weight_kg'],
            'length_m' => $row['length_m'],
            'width_m' => $row['width_m'],
            'height_m' => $row['height_m'],
            'enabled' => true,
            'priority' => 100,
        ];

        if ($existing === null) {
            if (! $dryRun) {
                PiguDimensionRule::query()->create([
                    'match_type' => 'collection',
                    'match_value' => $row['handle'],
                    ...$payload,
                ]);
            }

            return 'create';
        }

        if (! $dryRun) {
            $existing->update($payload);
        }

        return 'update';
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{property_name: string, value_source: string, value_name: ?string}  $property
     */
    private function upsertPropertyRule(array $row, array $property, bool $dryRun): string
    {
        $existing = PiguPropertyRule::query()
            ->where('pigu_category_id', $row['category_id'])
            ->where('match_type', 'collection')
            ->where('match_value', $row['handle'])
            ->where('property_name', $property['property_name'])
            ->first();

        $payload = [
            'value_source' => $property['value_source'],
            'value_name' => $property['value_name'],
            'enabled' => true,
            'priority' => 100,
        ];

        if ($existing === null) {
            if (! $dryRun) {
                PiguPropertyRule::query()->create([
                    'pigu_category_id' => $row['category_id'],
                    'match_type' => 'collection',
                    'match_value' => $row['handle'],
                    'property_name' => $property['property_name'],
                    ...$payload,
                ]);
            }

            return 'create';
        }

        if (! $dryRun) {
            $existing->update($payload);
        }

        return 'update';
    }

    /**
     * @param  array<int, string|null>  $csvRow
     * @param  array<string, int>  $map  normalized header => index
     */
    private function cell(array $csvRow, array $map, string $header): string
    {
        $key = $this->normalizeHeader($header);

        if (! array_key_exists($key, $map)) {
            return '';
        }

        return trim((string) ($csvRow[$map[$key]] ?? ''));
    }

    private function normalizeHeader(string $header): string
    {
        $normalized = mb_strtolower(trim($header));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return $normalized;
    }

    private function parseCategoryId(string $value): ?int
    {
        $trimmed = trim($value);

        if ($trimmed === '' || ! preg_match('/^\d+$/', $trimmed)) {
            return null;
        }

        return (int) $trimmed;
    }

    private function parsePositiveDecimal(string $value): ?float
    {
        $normalized = str_replace(',', '.', trim($value));

        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        $number = (float) $normalized;

        return $number > 0 ? $number : null;
    }

    private function parseBool(string $value): bool
    {
        return in_array(mb_strtolower(trim($value)), ['1', 'true', 'yes', 'y'], true);
    }

    /**
     * @param  array<int, string|null>  $row
     */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function stripBom(string $value): string
    {
        return preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
    }
}
