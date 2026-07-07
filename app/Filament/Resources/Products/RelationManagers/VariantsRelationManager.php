<?php

namespace App\Filament\Resources\Products\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('sku')
                    ->maxLength(255),
                TextInput::make('barcode')
                    ->maxLength(255),
                TextInput::make('title')
                    ->maxLength(255),
                TextInput::make('external_id')
                    ->maxLength(255),
                TextInput::make('price')
                    ->numeric()
                    ->default(0)
                    ->prefix('€'),
                TextInput::make('compare_at_price')
                    ->numeric()
                    ->prefix('€'),
                TextInput::make('weight')
                    ->numeric(),
                TextInput::make('weight_unit')
                    ->maxLength(255),
                TextInput::make('option1')
                    ->maxLength(255),
                TextInput::make('option1_name')
                    ->maxLength(255),
                TextInput::make('option1_value')
                    ->maxLength(255),
                TextInput::make('option2')
                    ->maxLength(255),
                TextInput::make('option2_name')
                    ->maxLength(255),
                TextInput::make('option2_value')
                    ->maxLength(255),
                TextInput::make('option3')
                    ->maxLength(255),
                TextInput::make('option3_name')
                    ->maxLength(255),
                TextInput::make('option3_value')
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')
                    ->searchable(),
                TextColumn::make('barcode')
                    ->searchable()
                    ->color(fn ($record) => blank($record->barcode) ? 'danger' : null),
                TextColumn::make('image_url')
                    ->label('Image')
                    ->url(fn ($record) => $record->image_url)
                    ->openUrlInNewTab()
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('title')
                    ->searchable(),
                TextColumn::make('option1_name')
                    ->label('Option 1 name')
                    ->toggleable(),
                TextColumn::make('option1_value')
                    ->label('Option 1 value')
                    ->toggleable(),
                TextColumn::make('option2_name')
                    ->label('Option 2 name')
                    ->toggleable(),
                TextColumn::make('option2_value')
                    ->label('Option 2 value')
                    ->toggleable(),
                TextColumn::make('option3_name')
                    ->label('Option 3 name')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('option3_value')
                    ->label('Option 3 value')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('price')
                    ->money('EUR')
                    ->sortable(),
                TextColumn::make('inventory_levels_sum_quantity')
                    ->sum('inventoryLevels', 'quantity')
                    ->label('Stock'),
            ])
            ->filters([
                Filter::make('missing_barcode')
                    ->label('Missing barcode')
                    ->query(fn (Builder $query): Builder => $query->where(fn (Builder $inner) => $inner->whereNull('barcode')->orWhere('barcode', ''))),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
