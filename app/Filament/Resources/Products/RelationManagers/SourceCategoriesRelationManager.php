<?php

namespace App\Filament\Resources\Products\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SourceCategoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'sourceCategories';

    protected static ?string $title = 'Source Categories';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->badge(),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('handle')
                    ->label('Handle')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('external_id')
                    ->label('External ID')
                    ->toggleable(),
            ])
            ->defaultSort('type');
    }
}
