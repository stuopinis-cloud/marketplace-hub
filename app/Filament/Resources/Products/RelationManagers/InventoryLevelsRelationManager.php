<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Models\InventoryLevel;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InventoryLevelsRelationManager extends RelationManager
{
    protected static string $relationship = 'inventoryLevels';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('variant_id')
                    ->relationship(
                        name: 'variant',
                        titleAttribute: 'sku',
                        modifyQueryUsing: fn ($query) => $query->where('product_id', $this->getOwnerRecord()->getKey()),
                    )
                    ->required()
                    ->searchable(),
                TextInput::make('warehouse_name')
                    ->maxLength(255),
                TextInput::make('quantity')
                    ->numeric()
                    ->default(0)
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('variant.sku')
                    ->label('Variant SKU')
                    ->searchable(),
                TextColumn::make('warehouse_name')
                    ->searchable(),
                TextColumn::make('quantity')
                    ->sortable()
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'danger'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->using(fn (array $data): InventoryLevel => InventoryLevel::query()->create($data)),
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
