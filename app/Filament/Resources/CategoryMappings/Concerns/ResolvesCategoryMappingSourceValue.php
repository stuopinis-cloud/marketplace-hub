<?php

namespace App\Filament\Resources\CategoryMappings\Concerns;

use App\Models\SourceCategory;
use Illuminate\Validation\ValidationException;

trait ResolvesCategoryMappingSourceValue
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function resolveCategoryMappingSourceValue(array $data): array
    {
        if (blank($data['source_value'] ?? null) && filled($data['source_category_id'] ?? null)) {
            $category = SourceCategory::query()->find($data['source_category_id']);

            if ($category !== null) {
                $data['source_value'] = $category->mappingSourceValue();
            }
        }

        unset($data['source_category_id'], $data['use_manual_source_value']);

        if (blank($data['source_value'] ?? null)) {
            throw ValidationException::withMessages([
                'source_value' => 'Source value is required. Select a source from the dropdown or enter a value manually.',
            ]);
        }

        $data['source_value'] = trim((string) $data['source_value']);

        return $data;
    }
}
