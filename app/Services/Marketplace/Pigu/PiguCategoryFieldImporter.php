<?php

namespace App\Services\Marketplace\Pigu;

use App\Models\PiguCategoryField;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class PiguCategoryFieldImporter
{
    /**
     * @return array{imported: int, updated: int, skipped: int}
     */
    public function import(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Category fields file not found: {$path}");
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $rows = match ($extension) {
            'xlsx', 'xls' => $this->rowsFromSpreadsheet($path),
            'csv' => $this->rowsFromCsv($path),
            default => throw new RuntimeException('Supported formats: xlsx, xls, csv.'),
        };

        $imported = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $categoryId = (int) ($row['lowest_category_id'] ?? 0);
            $fieldId = (int) ($row['field_id'] ?? 0);

            if ($categoryId <= 0 || $fieldId <= 0) {
                $skipped++;

                continue;
            }

            $payload = [
                'lowest_category_title' => $this->nullableString($row['lowest_category_title'] ?? null),
                'lowest_category_title_en' => $this->nullableString($row['lowest_category_title_en'] ?? null),
                'top_category_title' => $this->nullableString($row['top_category_title'] ?? null),
                'top_category_title_en' => $this->nullableString($row['top_category_title_en'] ?? null),
                'field_title' => $this->nullableString($row['field_title'] ?? null),
                'field_title_en' => $this->nullableString($row['field_title_en'] ?? null),
                'mandatory' => $this->toBool($row['mandatory'] ?? false),
                'field_value_type' => $this->nullableString($row['field_value_type'] ?? null),
                'lt_values' => $this->nullableString($row['lt values'] ?? $row['lt_values'] ?? null),
                'lv_values' => $this->nullableString($row['lv values'] ?? $row['lv_values'] ?? null),
                'ee_values' => $this->nullableString($row['ee values'] ?? $row['ee_values'] ?? null),
                'fi_values' => $this->nullableString($row['fi values'] ?? $row['fi_values'] ?? null),
                'en_values' => $this->nullableString($row['en values'] ?? $row['en_values'] ?? null),
            ];

            $existing = PiguCategoryField::query()
                ->where('lowest_category_id', $categoryId)
                ->where('field_id', $fieldId)
                ->first();

            if ($existing === null) {
                PiguCategoryField::query()->create([
                    'lowest_category_id' => $categoryId,
                    'field_id' => $fieldId,
                    ...$payload,
                ]);
                $imported++;
            } else {
                $existing->update($payload);
                $updated++;
            }
        }

        return compact('imported', 'updated', 'skipped');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsFromSpreadsheet(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $matrix = $sheet->toArray(null, true, true, false);

        if ($matrix === []) {
            return [];
        }

        $headers = array_map(fn ($value) => trim((string) $value), array_shift($matrix));
        $rows = [];

        foreach ($matrix as $line) {
            $row = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }
                $row[$header] = $line[$index] ?? null;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsFromCsv(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Unable to read CSV: {$path}");
        }

        try {
            $headers = fgetcsv($handle);

            if ($headers === false) {
                return [];
            }

            $headers = array_map(fn ($value) => trim((string) $value), $headers);
            $rows = [];

            while (($line = fgetcsv($handle)) !== false) {
                $row = [];
                foreach ($headers as $index => $header) {
                    if ($header === '') {
                        continue;
                    }
                    $row[$header] = $line[$index] ?? null;
                }
                $rows[] = $row;
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = mb_strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'y', 'yes', 'true', 'mandatory'], true);
    }
}
