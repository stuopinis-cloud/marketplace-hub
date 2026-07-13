<?php

namespace App\Filament\Resources\SupplierProducts;

use App\Filament\Resources\SupplierProducts\Pages\ListSupplierProducts;
use App\Models\SupplierProduct;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class SupplierProductResource extends Resource
{
    protected static ?string $model = SupplierProduct::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Suppliers';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Supplier Products';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('supplier_id')->relationship('supplier', 'name')->required(),
            TextInput::make('supplier_sku')->required(),
            Select::make('product_variant_id')->relationship('productVariant', 'sku')->searchable(),
            TextInput::make('stock_quantity')->numeric(),
            TextInput::make('match_status'),
            TextInput::make('match_method'),
            Toggle::make('enabled')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('supplier.name')->label('Supplier')->sortable()->searchable(),
                TextColumn::make('supplier_sku')->searchable()->sortable(),
                TextColumn::make('productVariant.product.title')->label('Product')->toggleable(),
                TextColumn::make('productVariant.sku')->label('Shopify SKU')->searchable(),
                TextColumn::make('productVariant.product.vendor')->label('Vendor')->toggleable(),
                TextColumn::make('stock_quantity')->sortable(),
                TextColumn::make('match_status')->badge(),
                TextColumn::make('match_method')->toggleable(),
                TextColumn::make('last_synced_at')->dateTime()->sortable(),
                IconColumn::make('stale')
                    ->label('Stale')
                    ->getStateUsing(fn (SupplierProduct $record): bool => $record->isStale())
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('supplier_id')->relationship('supplier', 'name')->label('Supplier'),
                SelectFilter::make('match_status')->options([
                    SupplierProduct::MATCH_STATUS_MATCHED => 'Matched',
                    SupplierProduct::MATCH_STATUS_UNMATCHED => 'Unmatched',
                    SupplierProduct::MATCH_STATUS_AMBIGUOUS => 'Ambiguous',
                ]),
                Filter::make('stock_positive')->label('Stock > 0')->query(fn (Builder $query): Builder => $query->where('stock_quantity', '>', 0)),
                Filter::make('stock_zero')->label('Stock = 0')->query(fn (Builder $query): Builder => $query->where('stock_quantity', '<=', 0)),
                Filter::make('stale')->label('Stale')->query(function (Builder $query): Builder {
                    return $query->where(function (Builder $outer): void {
                        $suppliers = \App\Models\Supplier::query()
                            ->whereNotNull('stale_after_minutes')
                            ->get(['id', 'stale_after_minutes']);

                        foreach ($suppliers as $supplier) {
                            $outer->orWhere(function (Builder $inner) use ($supplier): void {
                                $inner->where('supplier_id', $supplier->id)
                                    ->where(function (Builder $dateQuery) use ($supplier): void {
                                        $dateQuery->whereNull('last_synced_at')
                                            ->orWhere('last_synced_at', '<', now()->subMinutes((int) $supplier->stale_after_minutes));
                                    });
                            });
                        }
                    });
                }),
            ])
            ->defaultSort('last_synced_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupplierProducts::route('/'),
        ];
    }
}
