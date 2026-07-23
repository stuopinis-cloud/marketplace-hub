<?php

namespace App\Filament\Resources\CategoryMappings\Concerns;

use App\Models\MarketplaceChannel;
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
        $data = $this->normalizePiguTargetCategory($data);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalizePiguTargetCategory(array $data): array
    {
        $channelId = $data['marketplace_channel_id'] ?? null;

        if (blank($channelId)) {
            return $data;
        }

        $isPigu = MarketplaceChannel::query()
            ->whereKey($channelId)
            ->where('type', 'pigu')
            ->exists();

        if (! $isPigu) {
            return $data;
        }

        $id = trim((string) ($data['target_category_id'] ?? ''));
        $name = trim((string) ($data['target_category_name'] ?? ''));

        $data['target_category_id'] = $id !== '' ? $id : null;
        $data['target_category_name'] = $name !== '' ? $name : null;

        if ($id !== '' && $name !== '') {
            $data['target_category_path'] = $id.'|'.$name;
        } elseif (blank($data['target_category_path'] ?? null)) {
            // Satisfy NOT NULL path column while still failing export readiness without id/name.
            $data['target_category_path'] = $name !== '' ? $name : ($id !== '' ? $id : 'unmapped');
        }

        return $data;
    }
}
