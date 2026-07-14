<?php

namespace App\Filament\Resources\Products\Tables;

use App\Enums\ProductStatus;
use App\Enums\VarleExportStatus;
use App\Filament\Resources\Products\Actions\ViewVarleIssuesAction;
use App\Models\Product;
use App\Models\SourceCategory;
use App\Services\Marketplace\Varle\VarleIssueCodePresenter;
use App\Services\Marketplace\Varle\VarleReadinessRefreshService;
use App\Services\Marketplace\Varle\VarleReadinessService;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount(['variants', 'images']))
            ->columns([
                TextColumn::make('title')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $operator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
                        $like = '%'.$search.'%';

                        return $query->where(function (Builder $inner) use ($like, $operator): void {
                            $inner->where('title', $operator, $like)
                                ->orWhere('handle', $operator, $like)
                                ->orWhere('vendor', $operator, $like)
                                ->orWhereHas('variants', fn (Builder $variants): Builder => $variants
                                    ->where('sku', $operator, $like)
                                    ->orWhere('barcode', $operator, $like));
                        });
                    })
                    ->sortable()
                    ->limit(40),
                TextColumn::make('vendor')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('variants_count')
                    ->label('Variants')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('ready_for_varle')
                    ->label('Ready for Varle')
                    ->state(fn (Product $record): string => self::readyLabel($record))
                    ->badge()
                    ->color(fn (Product $record): string => self::readyColor($record))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('varle_is_ready', $direction)),
                TextColumn::make('varle_issue_count')
                    ->label('Issues')
                    ->badge()
                    ->color(fn (int $state): string => VarleIssueCodePresenter::issueCountColor($state))
                    ->sortable(),
                TextColumn::make('issue_codes_display')
                    ->label('Issue codes')
                    ->html()
                    ->state(fn (Product $record): HtmlString => self::issueCodesHtml(
                        is_array($record->varle_issue_codes) ? $record->varle_issue_codes : [],
                    )),
                TextColumn::make('varle_stock_status')
                    ->label('Stock')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? str($state)->replace('_', ' ')->title()->toString() : '—')
                    ->sortable(),
                TextColumn::make('varle_category_status')
                    ->label('Category')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? str($state)->replace('_', ' ')->title()->toString() : '—')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('handle')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('product_type')
                    ->label('Type')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label('Shopify status')
                    ->badge()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('varle_export_status')
                    ->label('Varle export')
                    ->badge()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('varle_barcode_status')
                    ->label('Barcode')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? str($state)->replace('_', ' ')->title()->toString() : '—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('varle_image_status')
                    ->label('Images')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? str($state)->replace('_', ' ')->title()->toString() : '—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('varle_vendor_delivery_rule_status')
                    ->label('Delivery rule')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? str($state)->replace('_', ' ')->title()->toString() : '—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('varle_delivery_text_preview')
                    ->label('Delivery preview')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('varle_exportable_variants_count')
                    ->label('Exportable')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('varle_skipped_variants_count')
                    ->label('Skipped')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('varle_readiness_cached_at')
                    ->label('Readiness cached')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('images_count')
                    ->label('Images #')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('imported_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('ready_for_varle')
                    ->label('Ready only')
                    ->query(fn (Builder $query): Builder => $query->where('varle_is_ready', true)),
                Filter::make('not_ready_for_varle')
                    ->label('Not ready only')
                    ->query(fn (Builder $query): Builder => $query->where(function (Builder $inner): void {
                        $inner->where('varle_is_ready', false)
                            ->orWhereNull('varle_is_ready')
                            ->orWhereNull('varle_readiness_cached_at');
                    })),
                Filter::make('has_issues')
                    ->label('Has issues')
                    ->query(fn (Builder $query): Builder => $query->where('varle_issue_count', '>', 0)),
                Filter::make('no_issues')
                    ->label('No issues')
                    ->query(fn (Builder $query): Builder => $query->where(function (Builder $inner): void {
                        $inner->where('varle_issue_count', 0)
                            ->orWhereNull('varle_issue_count');
                    })),
                SelectFilter::make('issue_code')
                    ->label('Specific issue code')
                    ->options(fn (): array => VarleIssueCodePresenter::filterOptions())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereJsonContains('varle_issue_codes', $data['value'])
                        : $query),
                Filter::make('missing_barcode_issue')
                    ->label('Missing barcode')
                    ->query(fn (Builder $query): Builder => $query->whereJsonContains('varle_issue_codes', 'missing_barcode')),
                Filter::make('missing_image_issue')
                    ->label('Missing image')
                    ->query(fn (Builder $query): Builder => $query->where(function (Builder $inner): void {
                        $inner->whereJsonContains('varle_issue_codes', 'missing_variant_image')
                            ->orWhereJsonContains('varle_issue_codes', 'no_images');
                    })),
                Filter::make('missing_category_mapping_issue')
                    ->label('Missing category mapping')
                    ->query(fn (Builder $query): Builder => $query->whereJsonContains('varle_issue_codes', 'missing_category_mapping')),
                Filter::make('no_exportable_stock')
                    ->label('No exportable stock')
                    ->query(fn (Builder $query): Builder => $query->where(function (Builder $inner): void {
                        $inner->where('varle_stock_status', 'no_exportable_stock')
                            ->orWhereJsonContains('varle_issue_codes', 'no_exportable_variants')
                            ->orWhereJsonContains('varle_issue_codes', 'no_stock_anywhere');
                    })),
                Filter::make('supplier_stock_stale')
                    ->label('Supplier stock stale')
                    ->query(fn (Builder $query): Builder => $query->whereJsonContains('varle_issue_codes', 'supplier_stock_stale')),
                Filter::make('pending_review_issue')
                    ->label('Pending review')
                    ->query(fn (Builder $query): Builder => $query->where(function (Builder $inner): void {
                        $inner->where('varle_export_status', VarleExportStatus::PendingReview)
                            ->orWhereJsonContains('varle_issue_codes', 'pending_review');
                    })),
                Filter::make('excluded_issue')
                    ->label('Excluded')
                    ->query(fn (Builder $query): Builder => $query->where(function (Builder $inner): void {
                        $inner->where('varle_export_status', VarleExportStatus::Exclude)
                            ->orWhereJsonContains('varle_issue_codes', 'excluded');
                    })),
                SelectFilter::make('status')
                    ->label('Shopify status')
                    ->options(ProductStatus::class),
                SelectFilter::make('varle_export_status')
                    ->label('Varle export status')
                    ->options(VarleExportStatus::class),
                SelectFilter::make('vendor')
                    ->options(fn (): array => Product::query()->whereNotNull('vendor')->where('vendor', '!=', '')->distinct()->orderBy('vendor')->pluck('vendor', 'vendor')->all())
                    ->searchable(),
                SelectFilter::make('product_type')
                    ->label('Product type')
                    ->options(fn (): array => Product::query()->whereNotNull('product_type')->where('product_type', '!=', '')->distinct()->orderBy('product_type')->pluck('product_type', 'product_type')->all())
                    ->searchable(),
                SelectFilter::make('source_category')
                    ->label('Collection')
                    ->options(fn (): array => SourceCategory::query()->where('type', 'collection')->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('sourceCategories', fn (Builder $inner): Builder => $inner->where('source_categories.id', $data['value']))
                        : $query),
                SelectFilter::make('varle_barcode_status')
                    ->label('Barcode status')
                    ->options([
                        'all_variants_have_barcode' => 'All variants have barcode',
                        'some_variants_missing_barcode' => 'Some missing barcode',
                        'no_barcodes' => 'No barcodes',
                    ]),
                SelectFilter::make('varle_image_status')
                    ->label('Image status')
                    ->options([
                        'all_exportable_variants_have_image' => 'All variant images',
                        'some_exportable_variants_missing_image' => 'Some missing variant images',
                        'no_variant_images' => 'No variant images',
                        'has_fallback_images' => 'Has fallback images',
                        'no_images' => 'No images',
                    ]),
                SelectFilter::make('varle_category_status')
                    ->label('Category status')
                    ->options([
                        'mapped' => 'Mapped',
                        'fallback' => 'Fallback',
                        'missing' => 'Missing',
                    ]),
                SelectFilter::make('varle_stock_status')
                    ->label('Stock status')
                    ->options([
                        'in_stock' => 'In stock',
                        'mixed_stock_backorder' => 'Mixed stock/backorder',
                        'backorder_only' => 'Backorder only',
                        'out_of_stock_blocked' => 'Out of stock blocked',
                        'no_exportable_stock' => 'No exportable stock',
                    ]),
                SelectFilter::make('varle_vendor_delivery_rule_status')
                    ->label('Delivery rule status')
                    ->options([
                        'vendor_rule_used' => 'Vendor rule used',
                        'default_rule_used' => 'Default rule used',
                        'vendor_disabled' => 'Vendor disabled',
                    ]),
                Filter::make('missing_barcode')
                    ->label('Variants missing barcode')
                    ->query(fn (Builder $query): Builder => $query->whereHas('variants', fn (Builder $inner): Builder => $inner->where(fn (Builder $q) => $q->whereNull('barcode')->orWhere('barcode', '')))),
                Filter::make('published')
                    ->label('Published only')
                    ->query(fn (Builder $query): Builder => $query->where('status', ProductStatus::Active)),
                Filter::make('unpublished')
                    ->label('Unpublished')
                    ->query(fn (Builder $query): Builder => $query->where('status', '!=', ProductStatus::Active)),
            ])
            ->recordActions([
                ViewVarleIssuesAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('refreshVarleReadiness')
                        ->label('Refresh readiness')
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->action(function (Collection $records): void {
                            $ids = $records->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

                            if (count($ids) === 1) {
                                $service = app(VarleReadinessService::class);
                                $context = $service->createRunContext();
                                $cached = $service->cache($records->first(), context: $context);

                                Notification::make()
                                    ->title('Readiness refreshed')
                                    ->body(sprintf(
                                        '1 product refreshed. Ready: %s.',
                                        $cached->varle_is_ready ? 'yes' : 'no',
                                    ))
                                    ->success()
                                    ->send();

                                return;
                            }

                            $result = app(VarleReadinessRefreshService::class)->dispatch($ids);

                            if ($result->alreadyRunning) {
                                Notification::make()
                                    ->title('Readiness refresh already running')
                                    ->body($result->message ?? 'A Varle readiness refresh is already running.')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title('Varle readiness refresh started in background.')
                                ->body(count($ids).' products queued in sync job #'.$result->syncJob?->id.'.')
                                ->success()
                                ->send();
                        }),
                    BulkAction::make('setVarleInclude')
                        ->label('Set include')
                        ->icon(Heroicon::OutlinedArrowUpCircle)
                        ->action(fn (Collection $records) => $records->each(fn (Product $product) => $product->update(['varle_export_status' => VarleExportStatus::Include]))),
                    BulkAction::make('setVarleExclude')
                        ->label('Set exclude')
                        ->icon(Heroicon::OutlinedNoSymbol)
                        ->action(fn (Collection $records) => $records->each(fn (Product $product) => $product->update(['varle_export_status' => VarleExportStatus::Exclude]))),
                    BulkAction::make('setPendingReview')
                        ->label('Set pending review')
                        ->icon(Heroicon::OutlinedClock)
                        ->action(fn (Collection $records) => $records->each(fn (Product $product) => $product->update(['varle_export_status' => VarleExportStatus::PendingReview]))),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->persistFiltersInSession()
            ->striped()
            ->defaultSort('varle_issue_count', 'desc');
    }

    private static function readyLabel(Product $record): string
    {
        if ($record->varle_readiness_cached_at === null) {
            return 'Unknown';
        }

        return $record->varle_is_ready ? 'Ready' : 'Not ready';
    }

    private static function readyColor(Product $record): string
    {
        if ($record->varle_readiness_cached_at === null) {
            return 'gray';
        }

        return $record->varle_is_ready ? 'success' : 'danger';
    }

    private static function issueCodesHtml(?array $codes): HtmlString
    {
        $badges = VarleIssueCodePresenter::badges($codes);

        if ($badges === []) {
            return new HtmlString('<span class="text-sm text-gray-500">—</span>');
        }

        $html = collect($badges)
            ->map(function (array $badge): string {
                $color = e($badge['color']);
                $label = e($badge['label']);

                return '<span class="fi-color fi-color-'.$color.' fi-badge fi-size-sm whitespace-nowrap"><span class="fi-badge-label">'.$label.'</span></span>';
            })
            ->implode(' ');

        return new HtmlString('<div class="flex flex-wrap gap-1">'.$html.'</div>');
    }
}
