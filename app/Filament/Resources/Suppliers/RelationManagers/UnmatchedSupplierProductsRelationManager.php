<?php

namespace App\Filament\Resources\Suppliers\RelationManagers;

use App\Models\SupplierProduct;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UnmatchedSupplierProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'supplierProducts';

    protected static ?string $title = 'Needs attention';

    public static function getBadge(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->supplierProducts()
            ->whereIn('match_status', [SupplierProduct::MATCH_STATUS_UNMATCHED, SupplierProduct::MATCH_STATUS_AMBIGUOUS])
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getBadgeColor(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): ?string
    {
        return 'danger';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('supplier_sku')->disabled(),
                Select::make('product_variant_id')
                    ->label('Manually link to Shopify variant')
                    ->relationship('productVariant', 'sku')
                    ->searchable(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('match_status', [
                SupplierProduct::MATCH_STATUS_UNMATCHED,
                SupplierProduct::MATCH_STATUS_AMBIGUOUS,
            ]))
            ->recordTitleAttribute('supplier_sku')
            ->columns([
                TextColumn::make('supplier_sku')->label('Supplier SKU')->searchable()->sortable(),
                TextColumn::make('raw_payload.barcode')->label('Barcode')->toggleable(),
                TextColumn::make('match_status')->badge()->color(fn (?string $state): string => $state === SupplierProduct::MATCH_STATUS_AMBIGUOUS ? 'warning' : 'danger'),
                TextColumn::make('stock_quantity')->sortable(),
                TextColumn::make('last_synced_at')->dateTime()->sortable(),
                TextColumn::make('last_seen_at')->dateTime()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('match_status')->options([
                    SupplierProduct::MATCH_STATUS_UNMATCHED => 'Unmatched',
                    SupplierProduct::MATCH_STATUS_AMBIGUOUS => 'Ambiguous',
                ]),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make()
                    ->label('Link variant')
                    ->modalHeading('Manually link to a Shopify variant'),
            ])
            ->defaultSort('last_synced_at', 'desc');
    }
}
