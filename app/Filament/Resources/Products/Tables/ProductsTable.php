<?php

namespace App\Filament\Resources\Products\Tables;

use App\Enums\ProductStatus;
use App\Enums\VarleExportStatus;
use App\Filament\Resources\Products\Actions\VarleDiagnosticsAction;
use App\Models\Product;
use App\Models\SourceCategory;
use App\Services\Marketplace\Varle\VarleReadinessService;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount(['variants', 'images']))
            ->columns([
                TextColumn::make('title')->searchable()->sortable()->limit(40),
                TextColumn::make('handle')->searchable()->toggleable(),
                TextColumn::make('vendor')->searchable()->sortable()->toggleable(),
                TextColumn::make('product_type')->label('Type')->searchable()->toggleable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('varle_export_status')->label('Varle export')->badge()->sortable(),
                IconColumn::make('varle_is_ready')->label('Ready')->boolean()->sortable(),
                TextColumn::make('varle_issue_count')->label('Issues')->badge()->color(fn (int $state): string => $state > 0 ? 'danger' : 'success')->sortable(),
                TextColumn::make('varle_barcode_status')->label('Barcode')->badge()->toggleable(),
                TextColumn::make('varle_image_status')->label('Images')->badge()->toggleable(),
                TextColumn::make('varle_category_status')->label('Category')->badge()->toggleable(),
                TextColumn::make('varle_stock_status')->label('Stock')->badge()->toggleable(),
                TextColumn::make('varle_delivery_text_preview')->label('Delivery')->toggleable(),
                TextColumn::make('variants_count')->label('Variants')->sortable(),
                TextColumn::make('varle_exportable_variants_count')->label('Exportable')->sortable()->toggleable(),
                TextColumn::make('images_count')->label('Images #')->toggleable(),
                TextColumn::make('imported_at')->dateTime()->sortable()->toggleable(),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(ProductStatus::class),
                SelectFilter::make('varle_export_status')->label('Varle export status')->options(VarleExportStatus::class),
                Filter::make('ready_for_varle')->label('Ready for Varle')->query(fn (Builder $query): Builder => $query->where('varle_is_ready', true)),
                Filter::make('has_issues')->label('Has issues')->query(fn (Builder $query): Builder => $query->where('varle_issue_count', '>', 0)),
                SelectFilter::make('vendor')->options(fn (): array => Product::query()->whereNotNull('vendor')->where('vendor', '!=', '')->distinct()->orderBy('vendor')->pluck('vendor', 'vendor')->all())->searchable(),
                SelectFilter::make('product_type')->label('Product type')->options(fn (): array => Product::query()->whereNotNull('product_type')->where('product_type', '!=', '')->distinct()->orderBy('product_type')->pluck('product_type', 'product_type')->all())->searchable(),
                SelectFilter::make('source_category')->label('Collection')->options(fn (): array => SourceCategory::query()->where('type', 'collection')->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('sourceCategories', fn (Builder $inner): Builder => $inner->where('source_categories.id', $data['value']))
                        : $query),
                SelectFilter::make('varle_barcode_status')->label('Barcode status')->options([
                    'all_variants_have_barcode' => 'All variants have barcode',
                    'some_variants_missing_barcode' => 'Some missing barcode',
                    'no_barcodes' => 'No barcodes',
                ]),
                SelectFilter::make('varle_image_status')->label('Image status')->options([
                    'all_exportable_variants_have_image' => 'All variant images',
                    'some_exportable_variants_missing_image' => 'Some missing variant images',
                    'no_variant_images' => 'No variant images',
                    'has_fallback_images' => 'Has fallback images',
                    'no_images' => 'No images',
                ]),
                SelectFilter::make('varle_category_status')->label('Category status')->options([
                    'mapped' => 'Mapped',
                    'fallback' => 'Fallback',
                    'missing' => 'Missing',
                ]),
                SelectFilter::make('varle_stock_status')->label('Stock status')->options([
                    'in_stock' => 'In stock',
                    'mixed_stock_backorder' => 'Mixed stock/backorder',
                    'backorder_only' => 'Backorder only',
                    'out_of_stock_blocked' => 'Out of stock blocked',
                    'no_exportable_stock' => 'No exportable stock',
                ]),
                Filter::make('missing_barcode')->label('Missing barcode')->query(fn (Builder $query): Builder => $query->whereHas('variants', fn (Builder $inner): Builder => $inner->where(fn (Builder $q) => $q->whereNull('barcode')->orWhere('barcode', '')))),
                Filter::make('published')->label('Published only')->query(fn (Builder $query): Builder => $query->where('status', ProductStatus::Active)),
                Filter::make('unpublished')->label('Unpublished')->query(fn (Builder $query): Builder => $query->where('status', '!=', ProductStatus::Active)),
            ])
            ->recordActions([
                VarleDiagnosticsAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('refreshVarleReadiness')->label('Refresh Varle readiness')->icon(Heroicon::OutlinedArrowPath)
                        ->action(function (Collection $records): void {
                            $service = app(VarleReadinessService::class);
                            $records->each(fn (Product $product) => $service->cache($product));
                        }),
                    BulkAction::make('setVarleInclude')->label('Set Varle include')->icon(Heroicon::OutlinedArrowUpCircle)
                        ->action(fn (Collection $records) => $records->each(fn (Product $product) => $product->update(['varle_export_status' => VarleExportStatus::Include]))),
                    BulkAction::make('setVarleExclude')->label('Set Varle exclude')->icon(Heroicon::OutlinedNoSymbol)
                        ->action(fn (Collection $records) => $records->each(fn (Product $product) => $product->update(['varle_export_status' => VarleExportStatus::Exclude]))),
                    BulkAction::make('setPendingReview')->label('Set pending review')->icon(Heroicon::OutlinedClock)
                        ->action(fn (Collection $records) => $records->each(fn (Product $product) => $product->update(['varle_export_status' => VarleExportStatus::PendingReview]))),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('updated_at', 'desc');
    }
}
