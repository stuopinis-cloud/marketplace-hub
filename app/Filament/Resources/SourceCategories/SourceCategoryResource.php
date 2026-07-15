<?php

namespace App\Filament\Resources\SourceCategories;

use App\Filament\Resources\SourceCategories\Pages\EditSourceCategory;
use App\Filament\Resources\SourceCategories\Pages\ListSourceCategories;
use App\Filament\Resources\SourceCategories\Schemas\SourceCategoryForm;
use App\Filament\Resources\SourceCategories\Tables\SourceCategoriesTable;
use App\Models\SourceCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SourceCategoryResource extends Resource
{
    protected static ?string $model = SourceCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolderOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Shopify Categories';

    protected static ?string $modelLabel = 'Source Category';

    protected static ?string $pluralModelLabel = 'Shopify Categories';

    public static function form(Schema $schema): Schema
    {
        return SourceCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SourceCategoriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSourceCategories::route('/'),
            'edit' => EditSourceCategory::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
