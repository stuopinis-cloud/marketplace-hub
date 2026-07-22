<?php

namespace App\Filament\Resources\Suppliers\RelationManagers;

use App\Models\SupplierProduct;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SupplierProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'supplierProducts';

    protected static ?string $title = 'Supplier products';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('supplier_sku')->required(),
                Select::make('product_variant_id')
                    ->label('Shopify variant')
                    ->relationship('productVariant', 'sku')
                    ->searchable(),
                TextInput::make('stock_quantity')->numeric(),
                Select::make('match_status')
                    ->options([
                        SupplierProduct::MATCH_STATUS_MATCHED => 'Matched',
                        SupplierProduct::MATCH_STATUS_UNMATCHED => 'Unmatched',
                        SupplierProduct::MATCH_STATUS_AMBIGUOUS => 'Ambiguous',
                    ]),
                Toggle::make('enabled')->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('supplier_sku')
            ->columns([
                TextColumn::make('supplier_sku')->label('Supplier SKU')->searchable()->sortable(),
                TextColumn::make('productVariant.sku')->label('Shopify SKU')->searchable(),
                TextColumn::make('productVariant.product.title')->label('Product')->limit(30)->toggleable(),
                TextColumn::make('stock_quantity')->sortable(),
                TextColumn::make('availability_status')->label('Availability')->badge()->toggleable(),
                TextColumn::make('match_status')->badge()->color(fn (?string $state): string => match ($state) {
                    SupplierProduct::MATCH_STATUS_MATCHED => 'success',
                    SupplierProduct::MATCH_STATUS_AMBIGUOUS => 'warning',
                    SupplierProduct::MATCH_STATUS_UNMATCHED => 'danger',
                    default => 'gray',
                }),
                TextColumn::make('match_method')->toggleable(),
                TextColumn::make('last_synced_at')->dateTime()->sortable(),
                IconColumn::make('stale')
                    ->label('Stale')
                    ->getStateUsing(fn (SupplierProduct $record): bool => $record->isStale())
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('match_status')->options([
                    SupplierProduct::MATCH_STATUS_MATCHED => 'Matched',
                    SupplierProduct::MATCH_STATUS_UNMATCHED => 'Unmatched',
                    SupplierProduct::MATCH_STATUS_AMBIGUOUS => 'Ambiguous',
                ]),
                Filter::make('stock_positive')->label('Stock > 0')->query(fn (Builder $query): Builder => $query->where('stock_quantity', '>', 0)),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('last_synced_at', 'desc');
    }
}
