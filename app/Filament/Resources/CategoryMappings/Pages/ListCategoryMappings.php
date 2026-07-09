<?php

namespace App\Filament\Resources\CategoryMappings\Pages;

use App\Filament\Resources\CategoryMappings\Actions\ImportCategoryMappingsCsvAction;
use App\Filament\Resources\CategoryMappings\CategoryMappingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCategoryMappings extends ListRecords
{
    protected static string $resource = CategoryMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportCategoryMappingsCsvAction::make(),
            CreateAction::make(),
        ];
    }
}
