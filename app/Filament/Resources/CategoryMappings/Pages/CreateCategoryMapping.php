<?php

namespace App\Filament\Resources\CategoryMappings\Pages;

use App\Filament\Resources\CategoryMappings\CategoryMappingResource;
use App\Filament\Resources\CategoryMappings\Concerns\ResolvesCategoryMappingSourceValue;
use Filament\Resources\Pages\CreateRecord;

class CreateCategoryMapping extends CreateRecord
{
    use ResolvesCategoryMappingSourceValue;

    protected static string $resource = CategoryMappingResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->resolveCategoryMappingSourceValue($data);
    }
}
