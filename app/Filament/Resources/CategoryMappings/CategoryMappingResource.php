<?php

namespace App\Filament\Resources\CategoryMappings;

use App\Filament\Resources\CategoryMappings\Pages\CreateCategoryMapping;
use App\Filament\Resources\CategoryMappings\Pages\EditCategoryMapping;
use App\Filament\Resources\CategoryMappings\Pages\ListCategoryMappings;
use App\Filament\Resources\CategoryMappings\Schemas\CategoryMappingForm;
use App\Filament\Resources\CategoryMappings\Tables\CategoryMappingsTable;
use App\Models\CategoryMapping;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CategoryMappingResource extends Resource
{
    protected static ?string $model = CategoryMapping::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Marketplaces';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Category Mappings';

    protected static ?string $modelLabel = 'Category Mapping';

    public static function form(Schema $schema): Schema
    {
        return CategoryMappingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CategoryMappingsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategoryMappings::route('/'),
            'create' => CreateCategoryMapping::route('/create'),
            'edit' => EditCategoryMapping::route('/{record}/edit'),
        ];
    }
}
