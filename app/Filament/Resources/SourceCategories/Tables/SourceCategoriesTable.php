<?php

namespace App\Filament\Resources\SourceCategories\Tables;

use App\Enums\VarleExportStatus;
use App\Filament\Resources\SourceCategories\Actions\SourceCategoryBulkActions;
use App\Models\SourceCategory;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SourceCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => self::withProductStats($query))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('handle')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Source type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('products_count')
                    ->label('Products')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('included_products_count')
                    ->label('Included')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('excluded_products_count')
                    ->label('Excluded')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('pending_products_count')
                    ->label('Pending')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('auto_products_count')
                    ->label('Auto')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('ready_products_count')
                    ->label('Ready')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('not_ready_products_count')
                    ->label('Not ready')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('default_varle_export_status')
                    ->label('Default status')
                    ->badge()
                    ->formatStateUsing(fn (?VarleExportStatus $state): string => $state?->label() ?? '—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options([
                        'collection' => 'Collection',
                        'product_type' => 'Product type',
                        'tag' => 'Tag',
                    ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make(SourceCategoryBulkActions::make()),
            ])
            ->defaultSort('name');
    }

    public static function withProductStats(Builder $query): Builder
    {
        return $query->withCount([
            'products',
            'products as included_products_count' => fn (Builder $inner): Builder => $inner->where('varle_export_status', VarleExportStatus::Include),
            'products as excluded_products_count' => fn (Builder $inner): Builder => $inner->where('varle_export_status', VarleExportStatus::Exclude),
            'products as pending_products_count' => fn (Builder $inner): Builder => $inner->where('varle_export_status', VarleExportStatus::PendingReview),
            'products as auto_products_count' => fn (Builder $inner): Builder => $inner->where('varle_export_status', VarleExportStatus::Auto),
            'products as ready_products_count' => fn (Builder $inner): Builder => $inner->where('varle_is_ready', true),
            'products as not_ready_products_count' => fn (Builder $inner): Builder => $inner->where(function (Builder $statusQuery): void {
                $statusQuery->where('varle_is_ready', false)
                    ->orWhereNull('varle_is_ready');
            }),
        ]);
    }
}
