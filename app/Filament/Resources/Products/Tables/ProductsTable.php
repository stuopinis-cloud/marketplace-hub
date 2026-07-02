<?php

namespace App\Filament\Resources\Products\Tables;

use App\Enums\ProductStatus;
use App\Enums\VarleExportStatus;
use App\Models\Product;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->limit(50),
                TextColumn::make('varle_export_status')
                    ->label('Varle export')
                    ->badge()
                    ->sortable()
                    ->color(fn (VarleExportStatus $state): string => match ($state) {
                        VarleExportStatus::PendingReview => 'warning',
                        VarleExportStatus::Auto => 'success',
                        VarleExportStatus::Include => 'info',
                        VarleExportStatus::Exclude => 'danger',
                    })
                    ->icon(fn (VarleExportStatus $state): ?Heroicon => $state === VarleExportStatus::PendingReview
                        ? Heroicon::OutlinedExclamationTriangle
                        : null),
                TextColumn::make('source.name')
                    ->label('Source')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('external_id')
                    ->label('External ID')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('vendor')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('brand')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('variants_count')
                    ->counts('variants')
                    ->label('Variants'),
                TextColumn::make('imported_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ProductStatus::class),
                SelectFilter::make('varle_export_status')
                    ->label('Varle export status')
                    ->options(VarleExportStatus::class),
                Filter::make('pending_varle_review')
                    ->label('Pending Varle review')
                    ->query(fn (Builder $query): Builder => $query->where('varle_export_status', VarleExportStatus::PendingReview)),
                Filter::make('varle_auto')
                    ->label('Varle auto')
                    ->query(fn (Builder $query): Builder => $query->where('varle_export_status', VarleExportStatus::Auto)),
                Filter::make('varle_include')
                    ->label('Varle include')
                    ->query(fn (Builder $query): Builder => $query->where('varle_export_status', VarleExportStatus::Include)),
                Filter::make('varle_exclude')
                    ->label('Varle exclude')
                    ->query(fn (Builder $query): Builder => $query->where('varle_export_status', VarleExportStatus::Exclude)),
                SelectFilter::make('source_id')
                    ->relationship('source', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('missing_sku')
                    ->label('Missing SKU')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'variants',
                        fn (Builder $q) => $q->where(fn (Builder $inner) => $inner->whereNull('sku')->orWhere('sku', ''))
                    )),
                Filter::make('missing_barcode')
                    ->label('Missing barcode')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'variants',
                        fn (Builder $q) => $q->where(fn (Builder $inner) => $inner->whereNull('barcode')->orWhere('barcode', ''))
                    )),
                Filter::make('zero_stock')
                    ->label('Zero stock')
                    ->query(fn (Builder $query): Builder => $query->whereDoesntHave(
                        'variants.inventoryLevels',
                        fn (Builder $q) => $q->where('quantity', '>', 0)
                    )),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('approveVarleAuto')
                        ->label('Approve for Varle auto')
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->action(fn (Collection $records) => $records->each(
                            fn (Product $product) => $product->update(['varle_export_status' => VarleExportStatus::Auto]),
                        ))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('setVarleInclude')
                        ->label('Set Varle include')
                        ->icon(Heroicon::OutlinedArrowUpCircle)
                        ->action(fn (Collection $records) => $records->each(
                            fn (Product $product) => $product->update(['varle_export_status' => VarleExportStatus::Include]),
                        ))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('setVarleExclude')
                        ->label('Set Varle exclude')
                        ->icon(Heroicon::OutlinedNoSymbol)
                        ->action(fn (Collection $records) => $records->each(
                            fn (Product $product) => $product->update(['varle_export_status' => VarleExportStatus::Exclude]),
                        ))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('setPendingReview')
                        ->label('Set pending review')
                        ->icon(Heroicon::OutlinedClock)
                        ->action(fn (Collection $records) => $records->each(
                            fn (Product $product) => $product->update(['varle_export_status' => VarleExportStatus::PendingReview]),
                        ))
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('updated_at', 'desc');
    }
}
