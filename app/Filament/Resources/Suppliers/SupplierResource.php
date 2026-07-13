<?php

namespace App\Filament\Resources\Suppliers;

use App\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Models\Supplier;
use App\Services\Suppliers\Mtac\MtacFeedClient;
use App\Services\Suppliers\Mtac\MtacSupplierSyncOptions;
use App\Services\Suppliers\SupplierSyncManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Suppliers';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required(),
            TextInput::make('code')->required()->unique(ignoreRecord: true),
            Toggle::make('enabled')->default(true),
            TextInput::make('connector_type'),
            TextInput::make('endpoint_url')->columnSpanFull(),
            TextInput::make('stock_priority')->numeric()->default(100),
            TextInput::make('in_stock_delivery_text')->default('5-10 d.d.'),
            TextInput::make('backorder_delivery_text'),
            Toggle::make('allow_backorder_export')->default(false),
            Toggle::make('sync_enabled')->default(false),
            TextInput::make('sync_interval_minutes')->numeric(),
            TextInput::make('stale_after_minutes')->numeric(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->searchable()->sortable(),
                IconColumn::make('enabled')->boolean(),
                IconColumn::make('sync_enabled')->boolean()->label('Sync'),
                TextColumn::make('in_stock_delivery_text')->label('Delivery'),
                TextColumn::make('last_sync_at')->dateTime()->sortable(),
                TextColumn::make('last_sync_status')->badge(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('syncNow')
                    ->label('Sync now')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->action(function (Supplier $record): void {
                        if (blank($record->code)) {
                            Notification::make()->title('Supplier code is missing')->danger()->send();

                            return;
                        }

                        app(SupplierSyncManager::class)->sync((string) $record->code);
                        Notification::make()->title('Supplier sync finished')->success()->send();
                    }),
                Action::make('dryRun')
                    ->label('Dry run')
                    ->action(function (Supplier $record): void {
                        if (blank($record->code)) {
                            return;
                        }

                        app(SupplierSyncManager::class)->sync((string) $record->code, new MtacSupplierSyncOptions(dryRun: true));
                        Notification::make()->title('Dry run finished')->success()->send();
                    }),
                Action::make('testConnection')
                    ->label('Test connection')
                    ->action(function (Supplier $record): void {
                        if (blank($record->endpoint_url)) {
                            Notification::make()->title('Endpoint URL missing')->danger()->send();

                            return;
                        }

                        $ok = app(MtacFeedClient::class)->testConnection((string) $record->endpoint_url);
                        Notification::make()
                            ->title($ok ? 'Connection successful' : 'Connection failed')
                            ->{$ok ? 'success' : 'danger'}()
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSuppliers::route('/'),
            'edit' => EditSupplier::route('/{record}/edit'),
        ];
    }
}
