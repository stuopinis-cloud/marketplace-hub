<?php

namespace App\Filament\Resources\Suppliers;

use App\Filament\Resources\Suppliers\Actions\PreviewSupplierFeedAction;
use App\Filament\Resources\Suppliers\Pages\CreateSupplier;
use App\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Filament\Resources\Suppliers\Pages\ViewSupplier;
use App\Filament\Resources\Suppliers\RelationManagers\LatestSyncJobsRelationManager;
use App\Filament\Resources\Suppliers\RelationManagers\SupplierProductsRelationManager;
use App\Filament\Resources\Suppliers\RelationManagers\UnmatchedSupplierProductsRelationManager;
use App\Filament\Resources\Suppliers\Schemas\SupplierWizardSteps;
use App\Models\Supplier;
use App\Services\Suppliers\SupplierConnectionTester;
use App\Services\Suppliers\SupplierDryRunService;
use App\Services\Suppliers\SupplierSyncManager;
use App\Services\Sync\MarketplaceJobDispatcher;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Suppliers';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * The Create/Edit pages use {@see \Filament\Resources\Pages\Concerns\HasWizard}
     * and build their schema from {@see SupplierWizardSteps} directly, so this is
     * only used as a fallback (e.g. relation manager forms elsewhere).
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Wizard::make(SupplierWizardSteps::steps())->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount([
                'supplierProducts as matched_products_count' => fn (Builder $q) => $q->where('match_status', 'matched'),
                'supplierProducts as unmatched_products_count' => fn (Builder $q) => $q->whereIn('match_status', ['unmatched', 'ambiguous']),
            ]))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('connector_type')->label('Connector')->badge(),
                TextColumn::make('auth_type')->label('Auth')->toggleable(),
                IconColumn::make('enabled')->label('Active')->boolean(),
                IconColumn::make('sync_enabled')->boolean()->label('Sync'),
                IconColumn::make('due_now')
                    ->label('Due now')
                    ->boolean()
                    ->getStateUsing(fn (Supplier $record): bool => $record->enabled
                        && $record->sync_enabled
                        && app(SupplierSyncManager::class)->isDueForSync($record))
                    ->toggleable(),
                TextColumn::make('config.matching_strategy')
                    ->label('Matching')
                    ->formatStateUsing(fn (?string $state): string => $state ?: 'sku_global')
                    ->toggleable(),
                TextColumn::make('sync_interval_minutes')->label('Interval (min)')->toggleable(),
                IconColumn::make('force_daily_sync')->label('Forced')->boolean()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_sync_at')->label('Last sync')->dateTime()->sortable(),
                TextColumn::make('last_sync_status')->label('Status')->badge()->color(fn (?string $state): string => match ($state) {
                    'completed' => 'success',
                    'partial' => 'warning',
                    'failed' => 'danger',
                    'running' => 'info',
                    default => 'gray',
                }),
                TextColumn::make('last_sync_message')->label('Message')->limit(40)->toggleable(),
                TextColumn::make('matched_products_count')->label('Matched')->sortable()->toggleable(),
                TextColumn::make('unmatched_products_count')->label('Unmatched')->sortable()->toggleable()
                    ->color(fn (mixed $state): ?string => ((int) $state) > 0 ? 'danger' : null),
            ])
            ->filters([
                SelectFilter::make('connector_type')->options([
                    Supplier::CONNECTOR_XML_URL => 'XML URL',
                    Supplier::CONNECTOR_API => 'API / JSON (legacy)',
                    Supplier::CONNECTOR_JSON_API => 'JSON API',
                    Supplier::CONNECTOR_CSV_URL => 'CSV URL',
                    Supplier::CONNECTOR_CSV_UPLOAD => 'CSV Upload',
                    Supplier::CONNECTOR_MTAC => 'M-Tac',
                    Supplier::CONNECTOR_HELIK_API => 'Helikon / Direct-Action',
                ]),
                TernaryFilter::make('enabled')->label('Active'),
                TernaryFilter::make('sync_enabled')->label('Sync enabled'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                PreviewSupplierFeedAction::make(),
                Action::make('dryRunModal')
                    ->label('Dry run')
                    ->icon(Heroicon::OutlinedBeaker)
                    ->modalHeading('Dry run results')
                    ->modalWidth('5xl')
                    ->modalContent(fn (Supplier $record): View => view(
                        'filament.resources.suppliers.dry-run-result',
                        app(SupplierDryRunService::class)->run($record),
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                Action::make('testConnection')
                    ->label('Test connection')
                    ->icon(Heroicon::OutlinedSignal)
                    ->action(function (Supplier $record): void {
                        $ok = app(SupplierConnectionTester::class)->test($record);
                        Notification::make()
                            ->title($ok ? 'Connection successful' : 'Connection failed')
                            ->{$ok ? 'success' : 'danger'}()
                            ->send();
                    }),
                Action::make('syncNow')
                    ->label('Queue sync')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->requiresConfirmation()
                    ->action(function (Supplier $record): void {
                        if (blank($record->code)) {
                            Notification::make()->title('Supplier code is missing')->danger()->send();

                            return;
                        }

                        $result = app(MarketplaceJobDispatcher::class)->dispatchSupplierSync((string) $record->code);

                        if ($result->alreadyRunning) {
                            Notification::make()->title('Job already running')->body($result->message)->warning()->send();

                            return;
                        }

                        Notification::make()
                            ->title('Supplier sync queued')
                            ->body($result->message ?? 'Job started in background.')
                            ->success()
                            ->send();
                    }),
                Action::make('duplicate')
                    ->label('Duplicate')
                    ->icon(Heroicon::OutlinedDocumentDuplicate)
                    ->requiresConfirmation()
                    ->modalDescription('Creates an inactive copy of this supplier with the same config but no credentials or sync history.')
                    ->action(function (Supplier $record): void {
                        $copy = $record->replicate(['credentials', 'last_sync_at', 'last_sync_status', 'last_sync_message']);
                        $copy->name = $record->name.' (copy)';
                        $copy->code = null;
                        $copy->enabled = false;
                        $copy->sync_enabled = false;
                        $copy->credentials = [];
                        $copy->save();

                        Notification::make()
                            ->title('Supplier duplicated')
                            ->body('Set a unique code and review credentials before enabling the copy.')
                            ->success()
                            ->send();
                    }),
                Action::make('disable')
                    ->label('Disable')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->visible(fn (Supplier $record): bool => $record->enabled)
                    ->requiresConfirmation()
                    ->action(function (Supplier $record): void {
                        $record->update(['enabled' => false, 'sync_enabled' => false]);

                        Notification::make()->title('Supplier disabled')->success()->send();
                    }),
            ])
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [
            SupplierProductsRelationManager::class,
            UnmatchedSupplierProductsRelationManager::class,
            LatestSyncJobsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSuppliers::route('/'),
            'create' => CreateSupplier::route('/create'),
            'edit' => EditSupplier::route('/{record}/edit'),
            'view' => ViewSupplier::route('/{record}'),
        ];
    }
}
