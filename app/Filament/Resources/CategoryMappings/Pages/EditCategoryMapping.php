<?php

namespace App\Filament\Resources\CategoryMappings\Pages;

use App\Filament\Resources\CategoryMappings\CategoryMappingResource;
use App\Models\SourceCategory;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCategoryMapping extends EditRecord
{
    protected static string $resource = CategoryMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (! in_array($data['source_type'] ?? null, ['collection', 'product_type', 'tag'], true)) {
            return $data;
        }

        $sourceCategory = SourceCategory::findForMapping(
            (string) $data['source_type'],
            (string) ($data['source_value'] ?? ''),
        );

        if ($sourceCategory === null) {
            $data['use_manual_source_value'] = true;

            return $data;
        }

        $data['source_category_id'] = $sourceCategory->id;
        $data['use_manual_source_value'] = $sourceCategory->mappingSourceValue() !== ($data['source_value'] ?? '');

        return $data;
    }
}
