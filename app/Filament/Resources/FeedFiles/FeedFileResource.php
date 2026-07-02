<?php

namespace App\Filament\Resources\FeedFiles;

use App\Filament\Resources\FeedFiles\Pages\CreateFeedFile;
use App\Filament\Resources\FeedFiles\Pages\EditFeedFile;
use App\Filament\Resources\FeedFiles\Pages\ListFeedFiles;
use App\Filament\Resources\FeedFiles\Schemas\FeedFileForm;
use App\Filament\Resources\FeedFiles\Tables\FeedFilesTable;
use App\Models\FeedFile;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class FeedFileResource extends Resource
{
    protected static ?string $model = FeedFile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Marketplaces';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'filename';

    public static function form(Schema $schema): Schema
    {
        return FeedFileForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FeedFilesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFeedFiles::route('/'),
            'create' => CreateFeedFile::route('/create'),
            'edit' => EditFeedFile::route('/{record}/edit'),
        ];
    }
}
